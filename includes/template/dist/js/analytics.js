    (() => {
    let active = true;
    let seconds = 0;
    let interval = null;

    // 🔐 session id per tab
    const sessionId = crypto.randomUUID();

    function startCounting() {
    if (interval) return;

    interval = setInterval(() => {
    if (active) {
    seconds++;
}
}, 1000);
}

    function stopCounting() {
    clearInterval(interval);
    interval = null;
}

    document.addEventListener("visibilitychange", () => {
    active = !document.hidden;
});

    window.addEventListener("focus", () => active = true);
    window.addEventListener("blur", () => active = false);

    setInterval(() => {
    if (seconds === 0) return;

    navigator.sendBeacon(
    "/api/analytics/heartbeat",
    JSON.stringify({
    page: location.pathname,
    seconds: seconds,
    session_id: sessionId
})
    );

    seconds = 0;
}, 15000);

    startCounting();
})();
