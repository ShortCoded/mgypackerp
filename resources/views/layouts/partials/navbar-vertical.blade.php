<nav class="navbar navbar-light navbar-vertical navbar-expand-xl" style="display:none;">
    <script>
        var navbarStyle = localStorage.getItem('navbarStyle');
        if (navbarStyle && navbarStyle !== 'transparent') {
            document.querySelector('.navbar-vertical').classList.add('navbar-' + navbarStyle);
        }
    </script>
    <div class="d-flex align-items-center">
        <div class="toggle-icon-wrapper">
            <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-toggle="tooltip" data-bs-placement="left" title="{{ __('layout.toggle_navigation') }}" type="button">
                <span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
            </button>
        </div>
        @include('layouts.partials.brand', ['innerClass' => 'py-3'])
    </div>
    <div class="collapse navbar-collapse" id="navbarVerticalCollapse">
        <div class="navbar-vertical-content scrollbar">
            @include('layouts.partials.sidebar')
        </div>
    </div>
</nav>
