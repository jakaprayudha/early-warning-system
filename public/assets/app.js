document.querySelectorAll('form').forEach((form) => {
    const password = form.querySelector('input[name="password"]');
    const confirmation = form.querySelector('input[name="password_confirmation"]');
    if (!password || !confirmation) return;

    const validatePasswords = () => {
        confirmation.setCustomValidity(
            confirmation.value && confirmation.value !== password.value
                ? 'Password tidak sama.'
                : ''
        );
    };
    password.addEventListener('input', validatePasswords);
    confirmation.addEventListener('input', validatePasswords);
});

const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
if (sidebarToggle && sidebarBackdrop) {
    const setSidebarOpen = (open) => {
        document.body.classList.toggle('sidebar-open', open);
        sidebarToggle.setAttribute('aria-expanded', String(open));
    };

    sidebarToggle.addEventListener('click', () => {
        setSidebarOpen(sidebarToggle.getAttribute('aria-expanded') !== 'true');
    });
    sidebarBackdrop.addEventListener('click', () => setSidebarOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setSidebarOpen(false);
    });
}
