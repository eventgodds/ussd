# Ghartey Event USSD Application

USSD voting application for event contests, integrated with Firebase and Arkesel.

## Features
- User voting system
- Real-time results viewing
- Firebase backend storage
- USSD menu navigation

## Deployment to Render

1. **Create Firebase Database**
   - Go to Firebase Console
   - Create new project
   - Enable Realtime Database
   - Get your database URL and secret

2. **Deploy to Render**
   - Push code to GitHub
   - Go to render.com
   - Create new Web Service
   - Connect GitHub repository
   - Settings:
     - Environment: `PHP`
     - Build Command: `composer install`
     - Start Command: `php -S 0.0.0.0:8080 index.php`

3. **Environment Variables on Render**
   - `FIREBASE_BASE_URL`: Your Firebase DB URL
   - `FIREBASE_AUTH_TOKEN`: Firebase secret

4. **Arkesel Configuration**
   - Go to Arkesel Dashboard
   - USSD Section
   - Set Callback URL: `https://your-app.onrender.com/index.php`
   - Format: JSON

## Local Testing

```bash
php -S localhost:8000 index.php
