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
