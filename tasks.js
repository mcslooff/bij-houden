(() => {
    const group = document.getElementById('task_group');
    const assignee = document.getElementById('assigned_to');
    if (!group || !assignee) return;

    const updateMembers = () => {
        for (const option of assignee.options) {
            if (option.value === '') continue;
            const available = option.dataset.group === group.value;
            option.disabled = !available;
            option.hidden = !available;
            if (!available && option.selected) assignee.value = '';
        }
    };
    group.addEventListener('change', updateMembers);
    updateMembers();
})();
