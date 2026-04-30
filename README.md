# Archiflow

A web-based document management system that allows users to upload, organize, search, and securely manage digital files through a centralized platform with role-based access control.

---

## Table of Contents

- [About](#about)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [Running the Application](#running-the-application)
- [Usage](#usage)
- [Contributing](#contributing)
- [License](#license)

---

## About

Archiflow is a centralized document management platform designed to streamline how individuals and teams store, organize, and access digital files. With a focus on security and usability, Archiflow provides role-based access control (RBAC) to ensure the right people have the right level of access to documents at all times.

---

## Features

- **File Upload & Storage** – Upload documents in various formats and store them securely in the cloud.
- **Folder Organization** – Create nested folder structures to keep files well-organized.
- **Full-Text Search** – Quickly locate files by name, tag, or content.
- **Role-Based Access Control** – Assign roles (e.g., Admin, Editor, Viewer) to control who can view, edit, or delete files.
- **Version History** – Track and restore previous versions of documents.
- **Secure Sharing** – Share files or folders with internal users or via secure external links.
- **Audit Logs** – Monitor user activity and document access history.

---

## Tech Stack

| Layer      | Technology              |
|------------|-------------------------|
| Frontend   | React / Next.js         |
| Backend    | Node.js / Express       |
| Database   | PostgreSQL               |
| Storage    | AWS S3 / Local Storage  |
| Auth       | JWT / OAuth 2.0         |
| Deployment | Docker / CI/CD Pipeline |

> **Note:** This table reflects the intended stack. Update it as the project evolves.

---

## Getting Started

### Prerequisites

Make sure you have the following installed:

- [Node.js](https://nodejs.org/) (v18 or higher)
- [npm](https://www.npmjs.com/) or [yarn](https://yarnpkg.com/)
- [Docker](https://www.docker.com/) (optional, for containerized setup)
- A running PostgreSQL instance

### Installation

1. **Clone the repository**

   ```bash
   git clone https://github.com/Kenu1030/Archiflow.git
   cd Archiflow
   ```

2. **Install dependencies**

   ```bash
   npm install
   ```

3. **Configure environment variables**

   Copy the example environment file and fill in your values:

   ```bash
   cp .env.example .env
   ```

   Key variables to set:

   | Variable           | Description                          |
   |--------------------|--------------------------------------|
   | `DATABASE_URL`     | PostgreSQL connection string         |
   | `JWT_SECRET`       | Secret key for JWT token signing     |
   | `STORAGE_BUCKET`   | S3 bucket name (if using AWS S3)     |
   | `PORT`             | Port the server listens on (default `3000`) |

4. **Run database migrations**

   ```bash
   npm run migrate
   ```

### Running the Application

**Development mode:**

```bash
npm run dev
```

**Production build:**

```bash
npm run build
npm start
```

**With Docker:**

```bash
docker compose up --build
```

The application will be available at `http://localhost:3000` by default.

---

## Usage

1. **Register / Log in** – Create an account or sign in with your credentials.
2. **Create folders** – Organize your workspace by creating nested folder structures.
3. **Upload files** – Drag and drop or browse to upload documents.
4. **Manage permissions** – Assign roles to team members from the Settings panel.
5. **Search** – Use the search bar to find files by name or content.
6. **Share** – Generate secure links or invite users directly to access specific files or folders.

---

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository.
2. Create a new branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "feat: add your feature"`
4. Push to the branch: `git push origin feature/your-feature-name`
5. Open a Pull Request.

Please make sure your code follows the project's coding standards and that all tests pass before submitting a PR.

---

## License

This project is licensed under the [MIT License](LICENSE).
