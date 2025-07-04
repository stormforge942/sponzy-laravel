<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Plans;
use Yabacon\Paystack;
use Yabacon\Paystack\Event;
use Illuminate\Http\Request;
use App\Models\AdminSettings;
use App\Models\Notifications;
use App\Models\Subscriptions;
use App\Models\PaymentGateways;

class PaystackController extends Controller
{
  use Traits\Functions;

  public function __construct(AdminSettings $settings, Request $request)
  {
    $this->settings = $settings::first();
    $this->request = $request;
  }

  // Card Authorization
  public function cardAuthorization()
  {
    $pystk = PaymentGateways::whereName('Paystack')->whereEnabled(1)->firstOrFail();

    $paystack = new Paystack($pystk->key_secret);

    try {
      $chargeAmount = ['NGN' => '50.00', 'GHS' => '0.10', 'ZAR' => '1', 'USD' => 0.20];

      if (array_key_exists($this->settings->currency_code, $chargeAmount)) {
        $chargeAmount = $chargeAmount[$this->settings->currency_code];
      } else {
        return back()->withErrorMessage(__('general.error_currency'));
      }

      $tranx = $paystack->transaction->initialize([
        'reusable' => true,
        'email' => auth()->user()->email,
        'amount' => $chargeAmount * 100,
        'currency' => $this->settings->currency_code,
        'callback_url' => url('paystack/card/authorization/verify')
      ]);

      // Redirect url
      $urlRedirect = $tranx->data->authorization_url;

      return redirect($urlRedirect);
    } catch (\Exception $e) {
      return back()->withErrorMessage($e->getMessage());
    }
  }

  // Card Authorization Verify
  public function cardAuthorizationVerify()
  {
    $pystk = PaymentGateways::whereName('Paystack')->whereEnabled(1)->firstOrFail();

    if (!$this->request->reference) {
      die('No reference supplied');
    }

    // initiate the Library's Paystack Object
    $paystack = new Paystack($pystk->key_secret);
    try {
      // verify using the library
      $tranx = $paystack->transaction->verify([
        'reference' => $this->request->reference, // unique to transactions
      ]);
    } catch (\Exception $e) {
      die($e->getMessage());
    }

    if ('success' === $tranx->data->status) {

      $user = User::find(auth()->id());
      $user->paystack_authorization_code = $tranx->data->authorization->authorization_code;
      $user->paystack_last4 = $tranx->data->authorization->last4;
      $user->paystack_exp = $tranx->data->authorization->exp_month . '/' . $tranx->data->authorization->exp_year;
      $user->paystack_card_brand = trim($tranx->data->authorization->card_type);
      $user->save();
    }

    return redirect('my/cards')->withSuccessMessage(__('general.success'));
  }



  /**
   * Redirect the User to Paystack Payment Page
   * @return Url
   */
  public function show()
  {
    if (!$this->request->expectsJson()) {
      abort(404);
    }

    if (auth()->user()->paystack_authorization_code == '') {
      return response()->json([
        "success" => false,
        'errors' => ['error' => __('general.please_add_payment_card')]
      ]);
    }

    // Find the user to subscribe
    $user = User::whereVerifiedId('yes')
      ->whereId($this->request->id)
      ->where('id', '<>', auth()->id())
      ->firstOrFail();

    // Check if Plan exists
    $plan = $user->plans()
      ->whereInterval($this->request->interval)
      ->firstOrFail();

    $payment = PaymentGateways::whereName('Paystack')
      ->whereEnabled(1)
      ->firstOrFail();

    try {
      // initiate the Library's Paystack Object
      $paystack = new Paystack($payment->key_secret);

      //========== Create Plan if no exists
      if (!$plan->paystack) {
        switch ($plan->interval) {
          case 'weekly':
            $interval = 'weekly';
            break;

          case 'monthly':
            $interval = 'monthly';
            break;

          case 'quarterly':
            $interval = 'quarterly';
            break;

          case 'biannually':
            $interval = 'biannually';
            break;

          case 'yearly':
            $interval = 'annually';
            break;
        }

        $userPlan = $paystack->plan->create([
          'name' => __('general.subscription_for') . ' @' . $user->username,
          'amount' => ($plan->price * 100),
          'interval' => $interval,
          'currency' => $this->settings->currency_code,
          'description' => http_build_query([
            'user' => $this->request->user()->id,
            'creator' => $user->id,
            'plan' => $plan->name,
            'interval' => $plan->interval,
          ])
        ]);

        $planCode = $userPlan->data->plan_code;

        // Insert Plan Code to User
        $plan->paystack = $planCode;
        $plan->save();
      } else {
        $planCode = $plan->paystack;

        try {
          $planCurrent = $paystack->plan->fetch(['id' => $planCode]);
          $pricePlanOnPaystack = ($planCurrent->data->amount / 100);

          // We check if the plan changed price
          if ($pricePlanOnPaystack != $plan->price) {
            // Update price
            $paystack->plan->update([
              'name' => __('general.subscription_for') . ' @' . $user->username,
              'amount' => ($plan->price * 100),
            ], ['id' => $planCode]);
          }
        } catch (\Exception $e) {
          return response()->json([
            "success" => false,
            'errors' => ['error' => $e->getMessage()]
          ]);
        }
      }

      //========== Create Subscription
      $subscription = $paystack->subscription->create([
        'plan' => $planCode,
        'customer' => auth()->user()->email,
        'start_date' => now(),
        'authorization' => auth()->user()->paystack_authorization_code
      ]);

      $paystack->plan->update([
        'description' => http_build_query([
          'user' => $this->request->user()->id,
          'creator' => $user->id,
          'plan' => $plan->name,
          'interval' => $plan->interval,
          'subsId' => $subscription->data->subscription_code,
        ]),
      ], [
        'id' => $planCode
      ]);

      // Send Email to User and Notification
      Subscriptions::sendEmailAndNotify(auth()->user()->name, $user->id);
    } catch (\Exception $exception) {
      return response()->json([
        'success' => false,
        'errors' => ['error' => $exception->getMessage()]
      ]);
    }

    return response()->json([
      'success' => true,
      'url' => route('subscription.success', ['user' => $user->username, 'delay' => 'paystack'])
    ]);
  }

  // PayStack webhooks
  public function webhooks()
  {
    // Get Payment Gateway
    $payment = PaymentGateways::whereName('Paystack')->whereEnabled(1)->firstOrFail();

    // Retrieve the request's body and parse it as JSON
    $event = Event::capture();
    http_response_code(200);

    /* It is a important to log all events received. Add code *
     * here to log the signature and body to db or file       */
    openlog('MyPaystackEvents', LOG_CONS | LOG_NDELAY | LOG_PID, LOG_USER | LOG_PERROR);
    syslog(LOG_INFO, $event->raw);
    closelog();

    /* Verify that the signature matches one of your keys*/
    $my_keys = [
      'live' => $payment->key_secret,
      'test' => $payment->key_secret,
    ];
    $owner = $event->discoverOwner($my_keys);
    if (!$owner) {
      // None of the keys matched the event's signature
      die();
    }

    switch ($event->obj->event) {
        // subscription.create
      case 'subscription.create':

        // Get all data
        $data = $event->obj->data;
        // Amount
        $amount = $data->amount / 100;
        // Subscription ID
        $subscrId = $data->subscription_code;
        // Metadata
        parse_str($data->plan->description ?? null, $metadata);

        if ($metadata) {
          $subscription = new Subscriptions();
          $subscription->user_id = $metadata['user'];
          $subscription->creator_id = $metadata['creator'];
          $subscription->stripe_price = $metadata['plan'];
          $subscription->subscription_id = $subscrId;
          $subscription->ends_at = null;
          $subscription->interval = $metadata['interval'];
          $subscription->save();
        }

        break;

        // charge.success
      case 'charge.success':

        if ('success' !== $event->obj->data->status) {
          return false;
        }

        // Get all data
        $data = $event->obj->data;
        // Amount
        $amount = ($data->amount / 100);
        // Metadata
        parse_str($data->plan->description ?? null, $metadata);

        //======== Renew subscription
        if (get_object_vars($data->plan)) {
          // Transaction reference
          $txnId = $data->reference;

          // Get subscription
          $subscription = Subscriptions::where('subscription_id', $metadata['subsId'])->firstOrFail();

          // User Plan
          $plan = Plans::whereName($subscription->stripe_price)->firstOrFail();

          // Admin and user earnings calculation
          $earnings = $this->earningsAdminUser($plan->user()->custom_fee, $amount, $payment->fee, $payment->fee_cents);

          // Insert Transaction
          $txn = $this->transaction(
            $txnId,
            $subscription->user_id,
            $subscription->id,
            $subscription->creator_id,
            $amount,
            $earnings['user'],
            $earnings['admin'],
            'Paystack',
            'subscription',
            $earnings['percentageApplied'],
            null
          );

          // Add Earnings to User
          $plan->user()->increment('balance', $txn->earning_net_user);

          // Update subscription
          $subscription->ends_at = $plan->user()->planInterval($plan->interval);
          $subscription->save();

          // Notify to user - destination, author, type, target
          Notifications::send($txn->subscribed, $txn->user_id, 12, $txn->user_id);
        }

        break;

      // invoice.payment_failed
      case 'invoice.payment_failed':

        // Get all data
        $data = $event->obj->data;
        // Subscription ID
        $subscrId = $data->subscription->subscription_code;

        // Update subscription
        $subscription = Subscriptions::where('subscription_id', $subscrId)->firstOrFail();
        $subscription->cancelled = 'yes';
        $subscription->save();

        break;

        // subscription.not_renew
      case 'subscription.not_renew':

        // Get all data
        $data = $event->obj->data;
        // Subscription ID
        $subscrId = $data->subscription_code;

        // Update subscription
        $subscription = Subscriptions::where('subscription_id', $subscrId)->firstOrFail();
        $subscription->cancelled = 'yes';
        $subscription->save();

        break;
    }
  }

  public function deletePaymentCard()
  {
    $payment = PaymentGateways::whereName('Paystack')->whereEnabled(1)->firstOrFail();

    $url = "https://api.paystack.co/customer/deactivate_authorization";
    $fields = [
      "authorization_code" => auth()->user()->paystack_authorization_code
    ];
    $fields_string = http_build_query($fields);
    //open connection
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      "Authorization: Bearer " . $payment->key_secret,
      "Cache-Control: no-cache",
    ));

    //So that curl_exec returns the contents of the cURL; rather than echoing it
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $result = json_decode($response);

    if ($err) {
      throw new \Exception("cURL Error #:" . $err);
    } else {
      if ($result->status) {

        $user = User::find(auth()->id());
        $user->paystack_authorization_code = '';
        $user->paystack_last4 = '';
        $user->paystack_exp = '';
        $user->paystack_card_brand = '';
        $user->save();

        return redirect('my/cards')->withSuccessRemoved(__('general.successfully_removed'));
      } else {
        return back()->withErrorMessage($result->message);
      }
    }
  }
}
