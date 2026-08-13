# Google Business Profile setup

Shoutrrr can create, delete, and reconcile Google Business Profile Local Posts
for locations that the connecting Google user is authorized to manage.

## Google Cloud configuration

1. Request Google Business Profile API access for the Google Cloud project and
   confirm that the project has quota. OAuth credentials alone are not enough.
2. Enable the Google My Business API, My Business Account Management API, and
   My Business Business Information API.
3. Configure an OAuth consent screen and add the
   `https://www.googleapis.com/auth/business.manage` scope. Use an External
   audience when users outside one Google Workspace will connect locations.
4. Create a Web application OAuth client and register this redirect URI,
   replacing the placeholder with the public application URL:

   ```text
   https://<app-domain>/accounts/callback/google-business-profile
   ```

5. Provide the following non-committed environment variables to Shoutrrr:

   ```text
   GOOGLE_BUSINESS_PROFILE_CLIENT_ID=
   GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET=
   GOOGLE_BUSINESS_PROFILE_API_APPROVED=true
   GOOGLE_BUSINESS_PROFILE_BASE_URL=https://mybusiness.googleapis.com/v4
   ```

Set `GOOGLE_BUSINESS_PROFILE_API_APPROVED=true` only after Google has approved
the project for Business Profile API access. Never commit OAuth JSON files,
client secrets, refresh tokens, or access tokens.

## Connect and publish

An instance administrator must enable Google Business Profile in **Settings →
Instance Platforms** after configuring the credentials. The user completing
OAuth consent must be an Owner or Manager of the location.

Shoutrrr checks Local Post access before allowing a location to be connected.
Start with a controlled test location. Google can process or reject Local Posts
after API acceptance, so review the returned post state and Google policy
requirements before using the integration broadly.
