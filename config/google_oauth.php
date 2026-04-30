<?php
// Google OAuth 2.0 configuration
// TODO: Fill these with your Google Cloud OAuth credentials
// Console: https://console.cloud.google.com/apis/credentials
return [
    // Your OAuth 2.0 Client ID (Web application)
    'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID') ?: 
    // Your Client Secret
    'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: 
    // Redirect URI: must match exactly what is set in Google Cloud Console
    // For local XAMPP default:
    // Revert to direct backend callback to avoid redirect loops and SameSite POST cookie issues
    'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: 
    // Space-separated scopes
    'scopes' => 
];
