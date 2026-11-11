(() => {
    const toggle = document.getElementById('sidebar-toggle');
    if (!toggle) return;

    const root = document.documentElement;
    const syncToggle = () => {
        const collapsed = root.dataset.sidebarCollapsed === 'true';
        const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
    };

    syncToggle();
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const collapsed = root.dataset.sidebarCollapsed !== 'true';
        root.dataset.sidebarCollapsed = String(collapsed);
        syncToggle();
        try {
            localStorage.setItem('bijhouden.sidebarCollapsed', String(collapsed));
        } catch (error) {
            // Navigation remains usable when browser storage is unavailable.
        }
    });
})();
