(function () {
    const token = localStorage.getItem('pos_token');
    const role = localStorage.getItem('pos_role');
    const path = window.location.pathname;

    // Determine current page status
    const isLoginPage = path.includes('login.html');
    const isIndexPage = path.endsWith('/') || path.includes('index.html');

    if (!token) {
        // If there is no token, redirect to login.html if not already there
        if (!isLoginPage) {
            window.location.href = './login.html';
        }
    } else {
        // If there is a token, check for role restrictions or redirect from entry pages
        if (isLoginPage || isIndexPage) {
            if (role === 'admin') {
                window.location.href = './admin.html';
            } else {
                window.location.href = './pos.html';
            }
        } else if (path.includes('admin.html') && role !== 'admin') {
            window.location.href = './pos.html';
        } else if (path.includes('pos.html') && role === 'admin') {
            // Note: Cashiers default to pos.html. If admin is visiting pos.html, they get redirected to admin dashboard
            window.location.href = './admin.html';
        }
    }
})();
