(() => {
    let collapsed = window.matchMedia('(max-width: 700px)').matches;
    try {
        const saved = localStorage.getItem('bijhouden.sidebarCollapsed');
        if (saved !== null) collapsed = saved === 'true';
    } catch (error) {}
    document.documentElement.dataset.sidebarCollapsed = String(collapsed);
})();
