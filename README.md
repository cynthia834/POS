# Supermarket POS System

This project contains a **Laravel (PHP)** backend architecture and a **React** frontend for a Supermarket Point of Sale system. 

## Project Structure

- `/backend`: Contains the Laravel architectural design for the POS system. Includes Models, Controllers, Observers, Jobs, and Pipelines to handle complex inventory logic, multi-payment options (including M-Pesa STK push), and discount pipelines. 
- `/frontend`: Contains the Vite + React frontend. Features a beautiful, responsive, glassmorphism-inspired UI with simulated barcode scanning, loyalty tiers, and cart logic.

## Prerequisites

Since this is a fresh setup, you will need the following installed on your machine to run the backend:
- **PHP** (v8.2+)
- **Composer**
- **MySQL**
- **Node.js & npm** (for the frontend)

## How to Run the Frontend

1. Navigate to the `frontend` directory:
   ```bash
   cd frontend
   ```
2. Install dependencies (already done if you're reading this right after generation):
   ```bash
   npm install
   ```
3. Start the Vite development server:
   ```bash
   npm run dev
   ```
4. Open the displayed `localhost` URL in your browser to interact with the POS.

## How to Run the Backend

*Note: Since PHP and Composer were not detected on the  system, the backend directory contains the architectural logic files but does not include the full vendor dependencies or base Laravel bootstrap files.*

To fully initialize the Laravel backend on a machine with PHP:
1. Run `composer create-project laravel/laravel new-backend` to generate a base Laravel project.
2. Copy the contents of `/backend/app` into your new Laravel project's `app` folder.
3. Configure your `.env` to point to a MySQL database.
4. Run migrations using `php artisan migrate`.
5. Start the API server using `php artisan serve`.
