// Deployment configuration for the static frontend.
//
// This is the single place the API origin is declared. It is read by app.js
// before anything else, and every page loads it ahead of the page script.
//
// Leave it empty for local development: an empty value makes every request
// relative, so `php -S localhost:8000` serves both the pages and the API from
// one origin with no CORS involved.
//
// Set it to the deployed API origin in production, e.g.
//   window.WE_API_BASE = "https://world-explorer-api.onrender.com";
//
// This file is served as-is with no build step, so the value here is what
// ships. Vercel environment variables are not readable from a static page, so
// changing the API origin means editing this file (or generating it as part of
// a build).
window.WE_API_BASE = "";
