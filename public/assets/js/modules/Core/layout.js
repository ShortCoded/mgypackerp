(function ($) {
    'use strict';

    function authHeaders() {
        return {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '',
            Accept: 'application/json'
        };
    }

    function applyDirection(direction) {
        var isRTL = direction === 'rtl';

        localStorage.setItem('isRTL', JSON.stringify(isRTL));
        $('html').attr('dir', isRTL ? 'rtl' : 'ltr');

        $('#style-default, #user-style-default').each(function () {
            this.disabled = isRTL;
        });

        $('#style-rtl, #user-style-rtl').each(function () {
            this.disabled = !isRTL;
        });
    }

    function initLanguageSelect() {
        var $select = $('.js-app-language-select');

        if ($select.length === 0) {
            return;
        }

        if ($.fn.select2) {
            $select.select2({
                theme: 'bootstrap-5',
                minimumResultsForSearch: Infinity,
                width: '100%',
                dropdownParent: $('#settings-offcanvas')
            });
        }

        $select.on('change', function () {
            var $currentSelect = $(this);
            var $option = $currentSelect.find(':selected');
            var direction = $option.data('dir') || 'ltr';
            var url = ($currentSelect.data('language-switch-url') || '').replace('__LOCALE__', $currentSelect.val());

            //applyDirection(direction);

            $.ajax({
                url: url,
                method: 'GET',
                headers: authHeaders()
            }).done(function () {
                window.location.reload();
            });
        });
    }

    var topMenuViewportGap = 12;

    function viewportBounds() {
        var viewport = window.visualViewport;
        var left = viewport ? viewport.offsetLeft : 0;
        var top = viewport ? viewport.offsetTop : 0;
        var width = viewport ? viewport.width : window.innerWidth;
        var height = viewport ? viewport.height : window.innerHeight;

        return {
            bottom: top + height,
            left: left,
            right: left + width,
            top: top
        };
    }

    function directOwnerMenu(owner) {
        return Array.prototype.find.call(owner.children, function (child) {
            return child.matches('.dropdown-menu');
        }) || null;
    }

    function resetTopMenuPosition(owner) {
        var menu = directOwnerMenu(owner);

        if (!menu) {
            return;
        }

        menu.style.removeProperty('--erp-menu-available-height');
        menu.style.removeProperty('bottom');
        menu.style.removeProperty('left');
        menu.style.removeProperty('right');
        menu.style.removeProperty('top');
        owner.classList.remove('erp-top-nav-branch-flipped');
    }

    function constrainTopMenuToViewport(owner) {
        var menu = directOwnerMenu(owner);

        if (!menu) {
            return;
        }

        var bounds = viewportBounds();
        var menuTop = Math.max(menu.getBoundingClientRect().top, bounds.top);
        var availableHeight = Math.max(0, Math.floor(bounds.bottom - menuTop - topMenuViewportGap));

        menu.style.setProperty('--erp-menu-available-height', availableHeight + 'px');
    }

    function positionNestedTopMenu(branch) {
        if (!branch) {
            return;
        }

        if (window.innerWidth < 992) {
            resetTopMenuPosition(branch);

            return;
        }

        var submenu = directOwnerMenu(branch);

        if (!submenu) {
            return;
        }

        var toggle = Array.prototype.find.call(branch.children, function (child) {
            return child.matches('[data-erp-menu-toggle]');
        });

        if (!toggle) {
            return;
        }

        branch.classList.remove('erp-top-nav-branch-flipped');
        submenu.style.removeProperty('bottom');
        submenu.style.removeProperty('left');
        submenu.style.removeProperty('right');
        submenu.style.removeProperty('top');

        var bounds = viewportBounds();
        var toggleRect = toggle.getBoundingClientRect();
        var submenuRect = submenu.getBoundingClientRect();
        var submenuHeight = Math.min(submenu.scrollHeight, bounds.bottom - bounds.top - (topMenuViewportGap * 2));
        var submenuWidth = submenuRect.width;
        var minimumTop = bounds.top + topMenuViewportGap;
        var maximumTop = bounds.bottom - topMenuViewportGap - submenuHeight;
        var top = Math.min(Math.max(toggleRect.top, minimumTop), maximumTop);
        var isRTL = document.documentElement.getAttribute('dir') === 'rtl';
        var overlap = 2;
        var preferredLeft = isRTL ? toggleRect.left - submenuWidth + overlap : toggleRect.right - overlap;
        var alternateLeft = isRTL ? toggleRect.right - overlap : toggleRect.left - submenuWidth + overlap;
        var fitsHorizontally = function (left) {
            return left >= bounds.left + topMenuViewportGap
                && left + submenuWidth <= bounds.right - topMenuViewportGap;
        };
        var shouldFlip = !fitsHorizontally(preferredLeft) && fitsHorizontally(alternateLeft);
        var left = shouldFlip ? alternateLeft : preferredLeft;
        var minimumLeft = bounds.left + topMenuViewportGap;
        var maximumLeft = bounds.right - topMenuViewportGap - submenuWidth;

        if (maximumLeft >= minimumLeft) {
            left = Math.min(Math.max(left, minimumLeft), maximumLeft);
        } else {
            left = minimumLeft;
        }

        branch.classList.toggle('erp-top-nav-branch-flipped', shouldFlip);
        submenu.style.setProperty('--erp-menu-available-height', Math.max(0, Math.floor(bounds.bottom - top - topMenuViewportGap)) + 'px');
        submenu.style.setProperty('left', Math.round(left) + 'px');
        submenu.style.setProperty('right', 'auto');
        submenu.style.setProperty('top', Math.round(top) + 'px');
    }

    function initHybridTopNavigation(navigation) {
        if (!navigation || navigation.dataset.erpTopNavigationInitialized === 'true') {
            return;
        }

        var ownerSelector = '.erp-top-nav-root, .erp-top-nav-branch';
        var toggleSelector = '[data-erp-menu-toggle]';
        var hoverMedia = window.matchMedia('(hover: hover) and (pointer: fine)');
        var openTimers = new WeakMap();
        var closeTimers = new WeakMap();
        var lastOpenedToggle = null;
        var positioningFrame = null;

        navigation.dataset.erpTopNavigationInitialized = 'true';

        function directChild(owner, selector) {
            return Array.prototype.find.call(owner.children, function (child) {
                return child.matches(selector);
            }) || null;
        }

        function ownerToggle(owner) {
            return directChild(owner, toggleSelector);
        }

        function ownerMenu(owner) {
            return directChild(owner, '.dropdown-menu');
        }

        function ownerState(owner) {
            return owner.dataset.erpMenuState || 'closed';
        }

        function clearTimer(timers, owner) {
            var timer = timers.get(owner);

            if (timer) {
                window.clearTimeout(timer);
                timers.delete(owner);
            }
        }

        function setOwnerClosed(owner) {
            var toggle = ownerToggle(owner);
            var menu = ownerMenu(owner);

            clearTimer(openTimers, owner);
            clearTimer(closeTimers, owner);
            owner.dataset.erpMenuState = 'closed';
            owner.classList.remove('show');

            if (toggle) {
                toggle.classList.remove('show');
                toggle.setAttribute('aria-expanded', 'false');
            }

            if (menu) {
                menu.classList.remove('show');
                menu.removeAttribute('data-bs-popper');
            }

            resetTopMenuPosition(owner);
        }

        function closeOwner(owner, force) {
            if (!force && ownerState(owner) === 'pinned') {
                return;
            }

            Array.prototype.slice.call(owner.querySelectorAll(ownerSelector)).reverse().forEach(setOwnerClosed);
            setOwnerClosed(owner);
        }

        function closeSiblingOwners(owner) {
            Array.prototype.forEach.call(owner.parentElement.children, function (sibling) {
                if (sibling !== owner && sibling.matches(ownerSelector)) {
                    closeOwner(sibling, true);
                }
            });
        }

        function setOwnerOpen(owner, state) {
            var toggle = ownerToggle(owner);
            var menu = ownerMenu(owner);

            if (!toggle || !menu) {
                return;
            }

            clearTimer(openTimers, owner);
            clearTimer(closeTimers, owner);

            if (ownerState(owner) !== 'pinned' || state === 'pinned') {
                owner.dataset.erpMenuState = state;
            }

            owner.classList.add('show');
            toggle.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
            menu.classList.add('show');
            menu.setAttribute('data-bs-popper', 'none');
            lastOpenedToggle = toggle;

            scheduleOpenMenuPositioning();
        }

        function positionOpenMenus() {
            positioningFrame = null;

            navigation.querySelectorAll('[data-erp-menu-state="hover"], [data-erp-menu-state="pinned"]').forEach(function (owner) {
                if (owner.classList.contains('erp-top-nav-branch')) {
                    positionNestedTopMenu(owner);
                } else if (window.innerWidth < 992) {
                    resetTopMenuPosition(owner);
                } else {
                    constrainTopMenuToViewport(owner);
                }
            });
        }

        function scheduleOpenMenuPositioning() {
            if (positioningFrame !== null) {
                return;
            }

            positioningFrame = window.requestAnimationFrame(positionOpenMenus);
        }

        function ownerPath(owner) {
            var path = [];
            var currentOwner = owner;

            while (currentOwner && navigation.contains(currentOwner)) {
                path.unshift(currentOwner);
                currentOwner = currentOwner.parentElement.closest(ownerSelector);
            }

            return path;
        }

        function openOwnerPath(owner, state) {
            ownerPath(owner).forEach(function (pathOwner) {
                closeSiblingOwners(pathOwner);
                setOwnerOpen(pathOwner, state);
            });
        }

        function togglePinnedOwner(owner) {
            if (ownerState(owner) === 'pinned') {
                closeOwner(owner, true);

                return;
            }

            openOwnerPath(owner, 'pinned');
        }

        function scheduleHoverOpen(owner) {
            clearTimer(closeTimers, owner);

            if (ownerState(owner) !== 'closed' || openTimers.has(owner)) {
                return;
            }

            openTimers.set(owner, window.setTimeout(function () {
                openTimers.delete(owner);
                openOwnerPath(owner, 'hover');
            }, 150));
        }

        function scheduleHoverClose(owner) {
            clearTimer(openTimers, owner);

            if (ownerState(owner) === 'pinned') {
                return;
            }

            clearTimer(closeTimers, owner);
            closeTimers.set(owner, window.setTimeout(function () {
                closeTimers.delete(owner);
                closeOwner(owner, false);
            }, 350));
        }

        function ownersFromTarget(target) {
            var owners = [];
            var owner = target.closest(ownerSelector);

            while (owner && navigation.contains(owner)) {
                owners.push(owner);
                owner = owner.parentElement.closest(ownerSelector);
            }

            return owners;
        }

        function closeAllMenus() {
            Array.prototype.forEach.call(navigation.querySelectorAll(':scope > .erp-top-nav-root'), function (owner) {
                closeOwner(owner, true);
            });
        }

        function hasOpenMenus() {
            return navigation.querySelector('[data-erp-menu-state="hover"], [data-erp-menu-state="pinned"]') !== null;
        }

        function directMenuItems(owner) {
            var menu = ownerMenu(owner);

            if (!menu) {
                return [];
            }

            return Array.prototype.filter.call(menu.querySelectorAll('.erp-top-nav-item'), function (item) {
                return item.closest('.dropdown-menu') === menu && !item.matches('.disabled, [aria-disabled="true"]');
            });
        }

        function focusMenuEdge(owner, focusLast) {
            var items = directMenuItems(owner);
            var item = focusLast ? items[items.length - 1] : items[0];

            if (item) {
                item.focus();
            }
        }

        function moveMenuFocus(item, direction) {
            var menu = item.closest('.dropdown-menu');

            if (!menu) {
                return;
            }

            var items = Array.prototype.filter.call(menu.querySelectorAll('.erp-top-nav-item'), function (candidate) {
                return candidate.closest('.dropdown-menu') === menu && !candidate.matches('.disabled, [aria-disabled="true"]');
            });
            var currentIndex = items.indexOf(item);
            var nextIndex = (currentIndex + direction + items.length) % items.length;

            if (items[nextIndex]) {
                items[nextIndex].focus();
            }
        }

        navigation.querySelectorAll(ownerSelector).forEach(setOwnerClosed);

        navigation.addEventListener('pointerover', function (event) {
            if (!hoverMedia.matches || window.innerWidth < 992 || event.pointerType === 'touch') {
                return;
            }

            var owners = ownersFromTarget(event.target);

            owners.forEach(function (owner) {
                clearTimer(closeTimers, owner);
            });

            if (owners[0] && (!event.relatedTarget || !owners[0].contains(event.relatedTarget))) {
                scheduleHoverOpen(owners[0]);
            }
        });

        navigation.addEventListener('pointerout', function (event) {
            if (!hoverMedia.matches || window.innerWidth < 992 || event.pointerType === 'touch') {
                return;
            }

            ownersFromTarget(event.target).forEach(function (owner) {
                if (!event.relatedTarget || !owner.contains(event.relatedTarget)) {
                    scheduleHoverClose(owner);
                }
            });
        });

        navigation.addEventListener('click', function (event) {
            var toggle = event.target.closest(toggleSelector);

            if (!toggle || !navigation.contains(toggle)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            togglePinnedOwner(toggle.closest(ownerSelector));
        });

        navigation.addEventListener('keydown', function (event) {
            var toggle = event.target.closest(toggleSelector);
            var item = event.target.closest('.erp-top-nav-item');

            if (toggle && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                event.stopPropagation();
                togglePinnedOwner(toggle.closest(ownerSelector));

                return;
            }

            if (toggle && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                event.preventDefault();
                openOwnerPath(toggle.closest(ownerSelector), 'pinned');
                focusMenuEdge(toggle.closest(ownerSelector), event.key === 'ArrowUp');

                return;
            }

            if (item && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                event.preventDefault();
                moveMenuFocus(item, event.key === 'ArrowDown' ? 1 : -1);
            }
        });

        navigation.addEventListener('focusin', function (event) {
            var branch = event.target.closest('.erp-top-nav-branch');

            if (branch) {
                scheduleOpenMenuPositioning();
            }
        });

        navigation.addEventListener('scroll', function (event) {
            if (event.target.matches('.erp-top-nav-menu, .erp-top-nav-submenu')) {
                scheduleOpenMenuPositioning();
            }
        }, true);

        document.addEventListener('click', function (event) {
            if (!navigation.contains(event.target)) {
                closeAllMenus();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !hasOpenMenus()) {
                return;
            }

            var focusedRoot = event.target.closest ? event.target.closest('.erp-top-nav-root') : null;
            var focusTarget = focusedRoot ? ownerToggle(focusedRoot) : lastOpenedToggle;

            closeAllMenus();

            if (focusTarget) {
                var rootOwner = focusTarget.closest('.erp-top-nav-root');
                var rootToggle = rootOwner ? ownerToggle(rootOwner) : focusTarget;

                rootToggle.focus();
            }
        });

        window.addEventListener('resize', function () {
            scheduleOpenMenuPositioning();

            if (!hoverMedia.matches || window.innerWidth < 992) {
                navigation.querySelectorAll('[data-erp-menu-state="hover"]').forEach(function (owner) {
                    closeOwner(owner, false);
                });
            }
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', scheduleOpenMenuPositioning);
            window.visualViewport.addEventListener('scroll', scheduleOpenMenuPositioning);
        }
    }

    function initTopNavigation() {
        document.querySelectorAll('[data-erp-top-navigation]').forEach(initHybridTopNavigation);
    }

    $(function () {
        initLanguageSelect();
        initTopNavigation();
    });
})(jQuery);
