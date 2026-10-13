<div align="center">
  
  <h1>🏥 QMS - Queue Management System</h1>
  <p><b>Automated & Manual Department-Wise Token Management for Diagnostics</b></p>
  
  <a href="https://services.smartjoans.space/"><img src="https://img.shields.io/badge/Developer-smartJOANS-orange?style=for-the-badge&logo=codeigniter" alt="Developer"></a>
  <img src="https://img.shields.io/badge/Client-SL_Diagnostics_Hyderabad-blue?style=for-the-badge&logo=hospital" alt="Client">
  <img src="https://img.shields.io/badge/Status-Production_Ready-success?style=for-the-badge&logo=checkmarx" alt="Status">
</div>

<br>

## 📋 Project Overview
This **Queue Management System (QMS)** was specifically built for **SL Diagnostics, Hyderabad**. It streamlines patient flow by managing queues through automated or manual department-wise token generation. Designed for efficiency, it seamlessly integrates with existing billing software databases to fetch patient details in real-time.

---

## ✨ Core Features
- 🔄 **Automated Token Generation:** Connects directly with billing software databases to instantly fetch patient information.
- ✍️ **Manual Token Generation:** Fallback option to generate tokens manually when required.
- 🗂️ **Department-Wise Sorting:** Automatically categorizes and routes tokens based on the specific medical department.
- 📢 **Announcement Display:** Features a client-side display screen option to broadcast announcements to waiting patients.
- 🔐 **Secure Access:** Built-in secure login system with default credentials and forced password reset capabilities.

---

## 🛠️ Installation & Setup

Follow these steps to deploy the QMS on your local server environment.

### 1️⃣ Prerequisites
- Install **XAMPP** (or any standard WAMP/LAMP stack) on your system.

### 2️⃣ Database Configuration
<details>
<summary><b>Click to view Database setup steps</b></summary>
<br>

1. Open your browser and navigate to `phpMyAdmin` (usually `http://localhost/phpmyadmin`).
2. Create a new database and name it `qms`.
3. Select the new `qms` database, go to the **Import** tab.
4. Upload and import the `qms.sql` file provided in this repository.

</details>

### 3️⃣ Application Setup
1. Copy the entire project folder to your server's web root directory (e.g., the `htdocs` folder in XAMPP).
2. Open the project folder and locate the configuration file: `db_connect.php`.
3. Update the database connection details (hostname, username, password, database name) inside `db_connect.php` to match your local setup.

---

## 🔌 Billing Software Integration

This system is engineered to connect directly with third-party billing software databases to dynamically fetch:
- **Patient Details**
- **Department Details**

> 💡 **Custom Integration:** If you need to integrate this QMS with your specific billing system, simply modify the database connection queries in the backend to point to your billing DB's tables.

---

## 🔐 Default Credentials

Use the following credentials for your initial login. 

| User Role | Username | Password |
| :--- | :--- | :--- |
| **Admin** | `admin` | `1234567` |
| **Standard Users** | *(Their specific Username)* | `1234567` |

> ⚠️ **SECURITY NOTE:** For security reasons, it is highly recommended to change your password immediately after your first login.

---

## 🤝 Support & Contact

Created by **smartJOANS**. If you need any help with installation, customization, or integrating this system with your existing billing software, feel free to reach out!

🌐 **Website:** [services.smartjoans.space](https://services.smartjoans.space)
