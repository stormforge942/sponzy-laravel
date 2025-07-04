<?php

namespace App\Models;

use App\Models\Notifications;
use Laravel\Cashier\Billable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use App\Notifications\ResetPassword as ResetPasswordNotification;

class User extends Authenticatable implements HasLocalePreference
{
  use Notifiable, Billable;

  const CREATED_AT = 'date';
  const UPDATED_AT = null;

  /**
   * The attributes that are mass assignable.
   *
   * @var array
   */
  protected $fillable = [
    'username',
    'countries_id',
    'name',
    'email',
    'password',
    'avatar',
    'cover',
    'status',
    'role',
    'permission',
    'confirmation_code',
    'oauth_uid',
    'oauth_provider',
    'token',
    'story',
    'verified_id',
    'ip',
    'language',
    'free_subscription',
    'stripe_connect_id',
    'completed_stripe_onboarding',
    'device_token',
    'document_id',
    'payment_gateway',
    'hide_name',
    'allow_live_streaming_private',
    'price_live_streaming_private',
    'allow_dm',
    'alias_mp',
    'cvu',
    'dark_mode'
  ];

  /**
   * The attributes that should be hidden for arrays.
   *
   * @var array
   */
  protected $hidden = [
    'password',
    'remember_token',
  ];

  protected $withCount = [
    'newNotifications',
    'newInbox'
  ];

  protected $casts = [
    'last_seen' => 'datetime:Y-m-d',
  ];

  /**
   * The tax rates that should apply to the customer's subscriptions.
   *
   * @return array
   */
  public function taxRates()
  {
    $taxRates = [];
    $payment = PaymentGateways::whereName('Stripe')
      ->whereEnabled('1')
      ->where('key_secret', '<>', '')
      ->first();

    if ($payment) {
      $stripe = new \Stripe\StripeClient($payment->key_secret);
      $taxes = $stripe->taxRates->all();

      foreach ($taxes->data as $tax) {
        if (
          $tax->active && $tax->state == $this->getRegion()
          && $tax->country == $this->getCountry()
          || $tax->active
          && $tax->country == $this->getCountry()
          && $tax->state == null
        ) {
          $taxRates[] = $tax->id;
        }
      }
    }

    return $taxRates;
  }

  public function isTaxable()
  {
    return TaxRates::whereStatus('1')
      ->whereIsoState($this->getRegion())
      ->whereCountry($this->getCountry())
      ->orWhere('country', $this->getCountry())
      ->whereNull('iso_state')
      ->whereStatus('1')
      ->get();
  }

  public function taxesPayable()
  {
    return $this->isTaxable()
      ->pluck('id')
      ->implode('_');
  }

  public function getCountry()
  {
    $ip = request()->ip();
    return cache('userCountry-' . $ip) ?? ($this->country()->country_code ?? null);
  }

  public function getRegion()
  {
    $ip = request()->ip();
    return cache('userRegion-' . $ip);
  }


  public function sendPasswordResetNotification($token)
  {
    $this->notify(new ResetPasswordNotification($token));
  }

  public function myPayments()
  {
    return $this->hasMany(Transactions::class);
  }

  public function myPaymentsReceived()
  {
    return $this->hasMany(Transactions::class, 'subscribed')->where('approved', '<>', '0');
  }

  public function updates()
  {
    return $this->hasMany(Updates::class)->where('status', 'active');
  }

  public function updatesPostDetail()
  {
    return $this->hasMany(Updates::class)->where('status', '<>', 'encode');
  }

  public function media()
  {
    return $this->belongsToMany(
      Updates::class,
      'media',
      'user_id',
      'updates_id'
    )
      ->where('updates.status', 'active')
      ->where('media.status', 'active');
  }

  public function withdrawals()
  {
    return $this->hasMany(Withdrawals::class);
  }

  public function country()
  {
    return $this->belongsTo(Countries::class, 'countries_id')->first();
  }

  public function notifications()
  {
    return $this->hasMany(Notifications::class, 'destination');
  }

  public function newNotifications()
  {
    return $this->notifications()->whereStatus('0');
  }

  public function newInbox()
  {
    return $this->hasMany(Messages::class, 'to_user_id')->where('status', 'new');
  }

  public function unseenNotifications()
  {
    return $this->new_notifications_count;
  }

  public function messagesInbox()
  {
    return $this->new_inbox_count;
  }

  public function comments()
  {
    return $this->hasMany(Comments::class);
  }

  public function likes()
  {
    return $this->hasMany(Like::class);
  }

  public function myLikes()
  {
    return $this->belongsToMany(Updates::class, 'likes', 'user_id', 'updates_id')->where('likes.status', '1');
  }

  public function category()
  {
    return $this->belongsTo(Categories::class, 'categories_id');
  }

  public function verificationRequests()
  {
    return $this->hasMany(VerificationRequests::class)->whereStatus('pending')->count();
  }

  public static function notificationsCount()
  {
    // Notifications Count
    $notifications_count = auth()->user()->unseenNotifications();
    // Messages
    $messages_count = auth()->user()->messagesInbox();

    if ($messages_count != 0 &&  $notifications_count != 0) {
      $totalNotifications = ($messages_count + $notifications_count);
    } elseif ($messages_count == 0 && $notifications_count != 0) {
      $totalNotifications = $notifications_count;
    } elseif ($messages_count != 0 && $notifications_count == 0) {
      $totalNotifications = $messages_count;
    } else {
      $totalNotifications = null;
    }

    return $totalNotifications;
  }

  function getFirstNameAttribute()
  {
    $name = explode(' ', $this->name);
    return $name[0] ?? null;
  }

  function getLastNameAttribute()
  {
    $name = explode(' ', $this->name);
    return $name[1] ?? null;
  }

  public function bookmarks()
  {
    return $this->belongsToMany(Updates::class, 'bookmarks', 'user_id', 'updates_id');
  }

  public function likesCount()
  {
    return $this->hasManyThrough(Like::class, Updates::class, 'user_id', 'updates_id')->where('likes.status', '=', '1')->count();
  }

  public function checkPayPerViewMsg($msgId)
  {
    return $this->payPerViewMessages()->where('messages_id', $msgId)->first();
  }

  public function userSubscriptionsActive()
  {
    return $this->userSubscriptions()
      ->select(['stripe_price', 'ends_at', 'stripe_status', 'free'])
      ->where('ends_at', '>=', now())
      ->orWhere('stripe_status', 'active')
      ->whereUserId($this->id)
      ->orWhere('free', 'yes')
      ->whereUserId($this->id)
      ->get();
  }

  public function subscriptionsActive()
  {
    return $this->mySubscriptions()
      ->where('stripe_id', '=', '')
      ->where('ends_at', '>=', now())
      ->orWhere('stripe_status', 'active')
      ->where('stripe_id', '<>', '')
      ->whereIn('stripe_price', $this->plans()->pluck('name'))
      ->orWhere('stripe_id', '=', '')
      ->where('stripe_price', $this->plan)
      ->where('free', '=', 'yes')
      ->first();
  }

  public function totalSubscriptionsActive()
  {
    $plans = $this->plans()->pluck('name');

    return $this->mySubscriptions()
      ->where('ends_at', '>=', now())
      ->whereIn('stripe_price', $plans)
      ->orWhere('stripe_status', 'active')
      ->where('stripe_id', '<>', '')
      ->whereIn('stripe_price', $plans)
      ->orWhere('free', '=', 'yes')
      ->whereIn('stripe_price', $plans)
      ->count();
  }

  public function payPerView()
  {
    return $this->belongsToMany(Updates::class, 'pay_per_views', 'user_id', 'updates_id');
  }


  public function payPerViewMessages()
  {
    return $this->belongsToMany(Messages::class, 'pay_per_views', 'user_id', 'messages_id');
  }

  /**
   * Get the user's preferred locale.
   */
  public function preferredLocale()
  {
    return $this->language;
  }

  /**
   * Get the user's is Super Admin.
   */
  public function isSuperAdmin()
  {
    if ($this->permissions == 'full_access') {
      return $this->id;
    }
    return false;
  }

  /**
   * Get the user's permissions.
   */
  public function hasPermission($section)
  {
    $permissions = explode(',', $this->permissions);

    return in_array($section, $permissions)
      || $this->permissions == 'full_access'
      || $this->permissions == 'limited_access'
      ? true
      : false;
  }

  /**
   * Get the user's blocked countries.
   */
  public function blockedCountries()
  {
    return explode(',', $this->blocked_countries);
  }

  /**
   * Get Referrals.
   */
  public function referrals()
  {
    return $this->hasMany(Referrals::class, 'referred_by');
  }

  public function referralTransactions()
  {
    return $this->hasMany(ReferralTransactions::class, 'referred_by');
  }

  /**
   * Broadcasting Live
   */
  public function isLive()
  {
    return $this->hasMany(LiveStreamings::class)
      ->where('updated_at', '>', now()->subMinutes(5))
      ->whereStatus('0')
      ->orderBy('id', 'desc')
      ->first();
  }

  /**
   * User plans
   */
  public function plans()
  {
    return $this->hasMany(Plans::class);
  }

  // Get details plan
  public function getPlan($interval, $field)
  {
    return $this->plans()
      ->whereInterval($interval)
      ->pluck($field)
      ->first();
  }

  // Set interval subscriptions
  public function planInterval($interval)
  {
    switch ($interval) {
      case 'weekly':
        return now()->add(7, 'days');
        break;

      case 'monthly':
        return now()->add(1, 'month');
        break;

      case 'quarterly':
        return now()->add(3, 'months');
        break;

      case 'biannually':
        return now()->add(6, 'months');
        break;

      case 'yearly':
        return now()->add(60, 'months');
        break;
    }
  }

  // Get Plan Active
  public function planActive()
  {
    return $this->plans()->whereStatus('1')->first();
  }

  public function purchasedItems()
  {
    return $this->hasMany(Purchases::class);
  }

  public function products()
  {
    return $this->hasMany(Products::class);
  }

  public function sales()
  {
    return $this->belongsToMany(
      Purchases::class,
      Products::class,
      'user_id',
      'id',
      'id',
      'products_id'
    );
  }

  public function restrictions()
  {
    return $this->hasMany(Restrictions::class);
  }

  public function isRestricted($user)
  {
    return Restrictions::whereUserId($this->id)
      ->whereUserRestricted($user)
      ->first();
  }

  public function checkRestriction($user)
  {
    return Restrictions::whereUserId($this->id)
      ->whereUserRestricted($user)
      ->orWhere('user_id', $user)
      ->whereUserRestricted($this->id)
      ->first();
  }

  public function oneSignalDevices()
  {
    return $this->hasMany(UserDevices::class);
  }

  public function replies()
  {
    return $this->hasMany(Replies::class);
  }

  public function scopeSelectFieldsUserExplorer($query)
  {
    return $query->select(
      'id',
      'name',
      'username',
      'avatar',
      'cover',
      'free_subscription',
      'hide_name',
      'featured'
    );
  }

  public function liveStreamingPrivateRequestPending()
  {
    return $this->hasMany(LiveStreamingPrivateRequest::class, 'creator_id')->whereStatus(0)->count();
  }
}
