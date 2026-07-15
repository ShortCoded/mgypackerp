<li class="nav-item dropdown" data-notifications-root>
    <a class="nav-link notification-indicator px-0 fa-icon-wait" id="navbarDropdownNotification" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-hide-on-body-scroll="data-hide-on-body-scroll">
        <span class="fas fa-bell" data-fa-transform="shrink-6" style="font-size: 33px;"></span>
        <span class="notification-indicator-number d-none" data-notifications-count>0</span>
    </a>
    <div class="dropdown-menu dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-menu-notification dropdown-caret-bg" aria-labelledby="navbarDropdownNotification">
        <div class="card card-notification shadow-none">
            <div class="card-header">
                <div class="row justify-content-between align-items-center">
                    <div class="col-auto">
                        <h6 class="card-header-title mb-0">{{ __('notifications.title') }}</h6>
                    </div>
                    <div class="col-auto d-flex align-items-center gap-2">
                        <button class="btn btn-link btn-sm p-0" type="button" data-notification-sound-toggle data-label-on="{{ __('notifications.sound.on') }}" data-label-off="{{ __('notifications.sound.off') }}" aria-pressed="true">
                            <span class="fas fa-volume-up me-1" data-notification-sound-icon></span><span data-notification-sound-label>{{ __('notifications.sound.on') }}</span>
                        </button>
                        <button class="btn btn-link btn-sm p-0" type="button" data-notifications-read-all>
                            {{ __('notifications.actions.mark_all_read') }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="scrollbar-overlay" style="max-height:19rem">
                <div class="list-group list-group-flush fw-normal fs-10" data-notifications-list>
                    <div class="list-group-item" data-notifications-empty>
                        <div class="notification notification-flush">
                            <div class="notification-avatar">
                                <div class="avatar avatar-2xl me-3">
                                    <div class="avatar-name rounded-circle bg-200 text-700"><span><span class="fas fa-bell"></span></span></div>
                                </div>
                            </div>
                            <div class="notification-body">
                                <p class="mb-1">{{ __('notifications.empty') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</li>
