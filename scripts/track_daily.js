(function() {
    var sentPageview = false;

    function send(path, obj) {
        var requestBody = JSON.stringify(obj);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(path, new Blob([requestBody], {
                type: "application/json"
            }));
        } else {
            fetch(path, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: requestBody,
                keepalive: true
            }).catch(function() {});
        }
    }

    // Send pageview on first real visibility
    function sendPageview() {
        if (sentPageview) return;
        sentPageview = true;
        send("track_daily.php", {
            url: location.href,
            referrer: document.referrer || "",
            language: navigator.language || "",
            timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone || ""),
            viewport_width: innerWidth,
            viewport_height: innerHeight,
            ts_pageview_ms: Date.now()
        });
    }

    var isPrerender = (document.prerendering === true);
    if (document.visibilityState === "visible" && !isPrerender) sendPageview();
    else document.addEventListener("visibilitychange", function() {
        if (document.visibilityState === "visible") sendPageview();
    }, {
        once: true
    });

    // Safety net: send pageview if page closes before visible
    addEventListener("pagehide", function() {
        if (!sentPageview) sendPageview();
    }, {
        once: true
    });
})();
