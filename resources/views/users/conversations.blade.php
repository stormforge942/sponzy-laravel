@extends('layouts.app')

@section('title') {{__('general.conversations')}} -@endsection

@section('css')
<style type="text/css">
  .fileuploader { display:block; padding: 0; }
  .fileuploader-items-list {margin: 10px 0 0 0;}
  .fileuploader-theme-dragdrop .fileuploader-input {
    background: {{ auth()->user()->dark_mode == 'on'? '#222' : '#fff' }};  
  }
</style>
@endsection

@section('content')
<section class="section section-sm">
    <div class="container">
      <div class="row justify-content-center text-center mb-sm">
        <div class="col-lg-8 py-5">
          <h2 class="mb-0 font-montserrat"><i class="feather icon-send mr-2"></i> {{__('general.conversations')}}</h2>
          <p class="lead text-muted mt-0">{{__('general.subtitle_conversations')}}</p>
        </div>
      </div>
      <div class="row">

        @include('includes.cards-settings')

        <div class="col-md-6 col-lg-9 mb-5 mb-lg-0">

          @if (session('status'))
                  <div class="alert alert-success">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                
                    <i class="bi-check2 mr-2"></i> {{ session('status') }}
                </div>
                @endif

          @include('errors.errors-forms')

          <form method="POST" action="{{ route('settings.conversations_update') }}">
            @csrf
              <div class="form-group">
                <div class="btn-block mb-4">
                  <div class="custom-control custom-switch custom-switch-lg">
                    <input type="checkbox" class="custom-control-input" name="allow_dm" value="1" @checked(auth()->user()->allow_dm) id="allow_dm">
                    <label class="custom-control-label switch" for="allow_dm">{{ __('general.receive_private_messages') }}</label>
                  </div>
                </div>

                <button class="btn btn-1 btn-success btn-block buttonActionSubmit" type="submit">{{__('general.save_changes')}}</button>

          </form>
        </div><!-- end col-md-6 -->
      </div>
    </div>
  </section>
@endsection

@section('javascript')
  <script src="{{ asset('public/js/fileuploader/fileuploader-welcome-msg.js') }}"></script>

  @if (session('encode'))
 <script type="text/javascript">
    swal({
      type: 'info',
      title: video_on_way,
      text: video_processed_info,
      confirmButtonText: ok
      });
    </script>
   @endif
@endsection