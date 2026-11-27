/*
 * Readers/resetters for the MySQL account widgets (controllers host-choice and privileges),
 * shared by the databases and MySQL users pages. They work on the controller root elements.
 */

/** Host chosen in a host-choice block; null when "custom" is selected but empty. */
export function hostValue(root) {
    const selected = root.querySelector('[data-host-choice-target="option"]:checked')?.value ?? '%';
    if ('__custom__' !== selected) return selected;
    const custom = root.querySelector('[data-host-choice-target="custom"]').value.trim();

    return custom || null;
}

export function resetHost(root) {
    root.querySelectorAll('[data-host-choice-target="option"]').forEach((o, i) => { o.checked = 0 === i; });
    const custom = root.querySelector('[data-host-choice-target="custom"]');
    custom.value = '';
    custom.hidden = true;
}

/** "ALL PRIVILEGES", the comma-separated single privileges, or '' when nothing is selected. */
export function privilegesValue(root) {
    if (root.querySelector('[data-privileges-target="all"]').checked) return 'ALL PRIVILEGES';

    return [...root.querySelectorAll('[data-privileges-target="single"]:checked')].map((cb) => cb.value).join(',');
}

/** Sets the checkboxes from a grant description ({all_privileges, privileges[]}) or clears them. */
export function setPrivileges(root, grant = null) {
    const all = root.querySelector('[data-privileges-target="all"]');
    const singles = root.querySelectorAll('[data-privileges-target="single"]');
    all.checked = Boolean(grant?.all_privileges);
    singles.forEach((cb) => {
        cb.disabled = all.checked;
        cb.checked = !all.checked && Boolean(grant?.privileges?.includes(cb.value));
    });
}
