// session_handler.js

// Session Management Section

var sessionTimer;       // Main session timer
var serverPingTimer;    // Secondary timer for server pings
var lastActivityTime = Date.now(); // Track the last time of user activity
var pingInterval = 5 * 60 * 1000;  // 5 minutes (in milliseconds)
var sessionExpiresAt;   // Timestamp when the session will expire
var nextServerPingAt;   // Timestamp when the next server ping will occur

// Function to send AJAX request to keep the session alive on the server
function pingServerToKeepSessionAlive() {

    return
    $.ajax({
        url: 'session_status.php',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.session_active) {
                console.log("Session is active. Time remaining: " + response.remaining_time + " seconds");
                resetSessionTimer(response.remaining_time * 1000); // Reset local client session timer
            } else {
                logoutUser(); // If session has expired on the server
            }
        },
        error: function() {
            console.error("Failed to ping server to keep session alive");
        }
    });
}

// Function to reset the session timer based on user activity (locally)
function resetSessionTimer(remainingTime = sessionTimeout) {
    clearTimeout(sessionTimer); // Clear the existing session timer
    sessionExpiresAt = Date.now() + remainingTime; // Update session expiration timestamp
    sessionTimer = setTimeout(logoutUser, remainingTime); // Set a new session timer
}

// Function to reset the activity timer (locally) and keep track of last activity time
function resetActivityTimer() {
    lastActivityTime = Date.now(); // Update the last activity time
    resetSessionTimer();           // Reset the session timer on user activity
}

// Event listeners for user activity (mouse movement, typing, etc.)
function addActivityListeners() {
    document.addEventListener('mousemove', resetActivityTimer);
    document.addEventListener('keydown', resetActivityTimer);
    document.addEventListener('click', resetActivityTimer);
    document.addEventListener('input', resetActivityTimer);
}

// Function to handle server pings at intervals (e.g., every 5 minutes)
function startServerPingInterval() {
    nextServerPingAt = Date.now() + pingInterval; // Initialize next server ping timestamp

    serverPingTimer = setInterval(function() {
        var currentTime = Date.now();

        // If the user has been active within the last pingInterval, ping the server
        if (currentTime - lastActivityTime < pingInterval) {
            pingServerToKeepSessionAlive();
        }

        nextServerPingAt = Date.now() + pingInterval; // Update next server ping timestamp
    }, pingInterval); // Ping the server every 5 minutes
}

// Logout function to handle session expiration
function logoutUser() {
    //alert("Your session has expired. Please log in again.");
    window.location.href = "logout.php";
}

// Function to update the session information display
function updateSessionInfoDisplay() {
    var currentTime = Date.now();

    // Calculate time until session expires
    var timeUntilSessionExpires = Math.max(0, sessionExpiresAt - currentTime);
    var timeUntilSessionExpiresSec = Math.floor(timeUntilSessionExpires / 1000);

    // Calculate time since last activity
    var timeSinceLastActivity = currentTime - lastActivityTime;
    var timeSinceLastActivitySec = Math.floor(timeSinceLastActivity / 1000);

    // Calculate time until next server ping
    var timeUntilNextServerPing = Math.max(0, nextServerPingAt - currentTime);
    var timeUntilNextServerPingSec = Math.floor(timeUntilNextServerPing / 1000);

    // Update the session-info div
    var sessionInfoDiv = document.getElementById('session-info');
    if (sessionInfoDiv) {
        if (timeUntilSessionExpiresSec <= 60) {
            var sessionInfoHtml = '<strong>Seconds until session expires:</strong> ' + timeUntilSessionExpiresSec;
            sessionInfoDiv.style.display = 'block';
        } else {
            var sessionInfoHtml = '';
            sessionInfoDiv.style.display = 'none';
        }
        sessionInfoDiv.innerHTML = sessionInfoHtml;
    }
}

// Initialize session handling when the document is ready
$(document).ready(function() {
    addActivityListeners();           // Start tracking user activity
    resetSessionTimer(sessionTimeout); // Initialize the session timer
    startServerPingInterval();        // Start the interval for server pings every 5 minutes

    // Start updating the session info display every second
    setInterval(updateSessionInfoDisplay, 1000);
});

