<script>
    (() => {
        const sidebar = document.getElementById('appSidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const storageKey = 'payroll-sidebar-collapsed';
        const sidebarTooltip = document.createElement('div');
        sidebarTooltip.className = 'pointer-events-none fixed z-50 hidden whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg';
        sidebarTooltip.setAttribute('role', 'tooltip');
        document.body.append(sidebarTooltip);

        const hideSidebarTooltip = () => sidebarTooltip.classList.add('hidden');
        const showSidebarTooltip = (link) => {
            if (!sidebar.classList.contains('sidebar-collapsed')) {
                hideSidebarTooltip();
                return;
            }

            const bounds = link.getBoundingClientRect();
            sidebarTooltip.textContent = link.dataset.tooltip;
            sidebarTooltip.classList.remove('hidden');
            sidebarTooltip.style.left = `${bounds.right + 10}px`;
            sidebarTooltip.style.top = `${bounds.top + (bounds.height - sidebarTooltip.offsetHeight) / 2}px`;
        };

        const setSidebarCollapsed = (collapsed) => {
            sidebar.classList.toggle('sidebar-collapsed', collapsed);
            sidebar.classList.toggle('w-64', !collapsed);
            sidebar.classList.toggle('w-20', collapsed);
            sidebar.querySelectorAll('.sidebar-label').forEach((label) => {
                label.classList.toggle('hidden', collapsed);
            });
            sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
                link.classList.toggle('justify-center', collapsed);
                link.classList.toggle('px-3', !collapsed);
                link.classList.toggle('px-0', collapsed);
            });
            sidebarToggle.classList.toggle('justify-center', collapsed);
            sidebarToggle.classList.toggle('px-3', !collapsed);
            sidebarToggle.classList.toggle('px-0', collapsed);
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarToggle.querySelector('.sidebar-label')?.replaceChildren(
                document.createTextNode(collapsed ? 'Expand sidebar' : 'Collapse sidebar')
            );
            sidebarToggleIcon.classList.toggle('bi-chevron-left', !collapsed);
            sidebarToggleIcon.classList.toggle('bi-chevron-right', collapsed);
        };

        let collapsed = false;
        try {
            collapsed = window.localStorage.getItem(storageKey) === 'true';
        } catch (error) {
            console.warn('Sidebar preference could not be read.', error);
        }
        setSidebarCollapsed(collapsed);

        sidebarToggle.addEventListener('click', () => {
            collapsed = !collapsed;
            setSidebarCollapsed(collapsed);
            hideSidebarTooltip();
            try {
                window.localStorage.setItem(storageKey, String(collapsed));
            } catch (error) {
                console.warn('Sidebar preference could not be saved.', error);
            }
        });

        sidebar.querySelectorAll('.sidebar-link[data-tooltip]').forEach((link) => {
            link.addEventListener('mouseenter', () => showSidebarTooltip(link));
            link.addEventListener('mouseleave', hideSidebarTooltip);
            link.addEventListener('focus', () => showSidebarTooltip(link));
            link.addEventListener('blur', hideSidebarTooltip);
        });

        window.addEventListener('resize', hideSidebarTooltip);
        sidebar.addEventListener('scroll', hideSidebarTooltip);

        const notificationButton = document.getElementById('notificationButton');
        const notificationMenu = document.getElementById('notificationMenu');
        const profileButton = document.getElementById('profileButton');
        const profileMenu = document.getElementById('profileMenu');
        const signOutButton = document.getElementById('signOutButton');
        const signOutDialog = document.getElementById('signOutDialog');
        const cancelSignOut = document.getElementById('cancelSignOut');

        const closeMenus = () => {
            notificationMenu.classList.add('hidden');
            profileMenu.classList.add('hidden');
            notificationButton.setAttribute('aria-expanded', 'false');
            profileButton.setAttribute('aria-expanded', 'false');
        };

        notificationButton.addEventListener('click', () => {
            const opening = notificationMenu.classList.contains('hidden');
            closeMenus();
            notificationMenu.classList.toggle('hidden', !opening);
            notificationButton.setAttribute('aria-expanded', String(opening));
        });

        profileButton.addEventListener('click', () => {
            const opening = profileMenu.classList.contains('hidden');
            closeMenus();
            profileMenu.classList.toggle('hidden', !opening);
            profileButton.setAttribute('aria-expanded', String(opening));
        });

        signOutButton.addEventListener('click', () => {
            closeMenus();
            signOutDialog.showModal();
        });

        cancelSignOut.addEventListener('click', () => signOutDialog.close());
        signOutDialog.addEventListener('click', (event) => {
            if (event.target === signOutDialog) {
                signOutDialog.close();
            }
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('#notificationButton, #notificationMenu, #profileButton, #profileMenu')) {
                closeMenus();
            }
        });
    })();
</script>
