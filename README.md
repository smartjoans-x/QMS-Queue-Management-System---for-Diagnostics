# QMS - Queue Management System for Diagnostics

This system was built for **SL Diagnostics, Hyderabad**. It helps manage patient queues by generating department-wise tokens automatically or manually. It can also integrate with billing software databases to fetch patient details.

---

## 🚀 Features
* **Automated Token Generation:** Connects with billing software databases to fetch patient information.
* **Manual Token Generation:** Tokens can be generated manually if needed.
* **Department-wise Sorting:** Automatically categorizes tokens based on the department.
* **Secure Login:** Default credentials for all users with an option to change passwords after the first login.

---

## 🛠️ Installation & Setup

### 1. Prerequisites
* Install **XAMPP** or any WAMP/LAMP stack on your system.

### 2. Database Configuration
* Open **phpMyAdmin**.
* Create a new database named `qms`.
* Import the `qms.sql` file provided in the repository into your newly created database.

### 3. Application Setup
* Copy the project folder to your `htdocs` directory.
* Configure your database connection details in the configuration file ( `db_connect.php`).

---

## 🔐 Default Credentials

| User Role | Username | Password |
| :--- | :--- | :--- |
| **Admin** | `admin` | `1234567` |
| **All Users** | *(Username)* | `1234567` |

> **Note:** For security reasons, please change your password immediately after your first login.

---

## 🔌 Integration
This system is designed to connect with billing software databases to fetch:
* Patient Details
* Department Details

If you need to integrate your specific billing system, you can modify the database connection settings to point to your billing DB.

---

## 📞 Support & Contact
If you need any help with installation, customization, or integration, feel free to reach out:

🌐 **Website:** [services.smartjoans.space](https://services.smartjoans.space)

---
 by **smartJOANS**
