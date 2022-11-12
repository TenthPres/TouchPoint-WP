function handleLoginPage() {
    // Parse query parameters
    const queryParams = {};
    const query = window.location.search.substring(1);
    const vars = query.split("&");
    for (let i = 0; i < vars.length; i++) {
        const pair = vars[i].split("=", 2);
        queryParams[pair[0]] = pair.length > 1 ? decodeURIComponent(pair[1]) : null;
    }

    // If logout is in progress, execute the redirect.
    if (queryParams['a'] === 'logout' && queryParams.hasOwnProperty('r')) {
        window.location = queryParams['r'];
    }
}
handleLoginPage();