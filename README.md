<div align="center">
  <img src="public/flygasal.png" alt="FlyGasal Logo" width="180" />
  <br />
  
  <h1>✈️ FlyGasal Flight Management System</h1>
  
  <p>
    <strong>Next-Generation B2B Flight Booking & Agency Management Platform</strong>
  </p>

  <p>
    <a href="#-features">Features</a> •
    <a href="#-tech-stack">Tech Stack</a> •
    <a href="#-getting-started">Getting Started</a> •
    <a href="#-screenshots">Screenshots</a>
  </p>

  ![React](https://img.shields.io/badge/React-20232A?style=for-the-badge&logo=react&logoColor=61DAFB)
  ![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)
  ![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
  ![Vite](https://img.shields.io/badge/Vite-B73BFE?style=for-the-badge&logo=vite&logoColor=white)
</div>

---

## 📖 Overview

**FlyGasal** is a comprehensive travel technology solution designed to bridge the gap between flight suppliers (like PKFare) and travel agencies. It features a dual-interface system: a **Client Portal** for agents to book flights and manage wallets, and a robust **Admin Dashboard** for operational oversight, financial auditing, and user management.

The platform focuses on a "World-Class" UI/UX, utilizing a clean **Bento Grid** layout, smooth transitions, and a distinctive Orange (`#EB7313`) brand identity.

## ✨ Features

### 🏢 Agent / Client Portal
- **Flight Search & Booking:** Real-time availability searching with complex filtering (Stops, Airlines, Price).
- **Digital Wallet System:** Secure top-up requests and instant wallet payments for bookings.
- **Itinerary Management:** Detailed booking history with status tracking (Issued, Pending, Cancelled).
- **E-Ticket Generation:** One-click PDF ticket generation with QR codes.
- **Responsive Design:** Fully optimized mobile and desktop experience.

### 🛡️ Admin Dashboard
- **Command Center:** High-level analytics with Sales Charts (Chart.js) and financial trends.
- **User Management:** Full CRUD for users with Role-Based Access Control (Admin, Agent, Client).
- **Financial Auditing:**
    - Transaction logs with filtering and export (Excel/CSV).
    - Wallet approval/rejection workflows.
    - Automated invoice generation.
- **System Configuration:** Manage API keys (PKFare), SMTP settings, and notification preferences via a GUI.

## 🛠 Tech Stack

### Frontend
- **Framework:** React 18
- **Build Tool:** Vite
- **Styling:** Tailwind CSS (Custom config with Brand Orange `#EB7313`)
- **Icons:** Lucide React & Heroicons
- **Charts:** Chart.js & React-Chartjs-2
- **Animations:** Framer Motion & Canvas Confetti
- **PDF/Excel:** jsPDF & XLSX
- **State Management:** Context API

### Backend (API)
- **Framework:** Laravel (PHP)
- **Database:** MySQL
- **Authentication:** Sanctum / JWT

## 📸 Screenshots

| Admin Dashboard | User Management |
|:---:|:---:|
<!-- | *Place your dashboard screenshot here* | *Place your user table screenshot here* | -->
| **Bento Grid Analytics** | **Advanced Filtering & Actions** |

| Booking Confirmation | Wallet System |
|:---:|:---:|
<!-- | *Place your confirmation page screenshot here* | *Place your wallet modal screenshot here* | -->
| **Digital Ticket UI** | **Top-up & Deduction Flow** |

## 🔗 Flight Search URL Format

The new TSX frontend (`flygasal/`) uses a path-based URL scheme for flight searches. The legacy JSX frontend (`flygasal-client/`) used query strings.

### TSX URL Format (path-based)

**One-way:**
```
/flights/{origin}-{destination}/{YYYY-MM-DD}/{cabin}/{adults}-{children}-{infants}
```
Example:
```
/flights/NBO-MBA/2026-08-24/economy/1-0-0
```

**Return (round-trip):**
```
/flights/{origin}-{stopover}-{destination}/{outDate}_{retDate}/{cabin}/{pax}
```
Example:
```
/flights/NBO-MBA-NBO/2026-08-24_2026-08-28/economy/2-1-0
```

**Multi-city:**
```
/flights/multi/{route1}-{route2}/.../{date1}_{date2}/.../{cabin}/{pax}
```

**Segment encoding:**
| Segment | Values |
|---|---|
| `{origin}-{destination}` | 3-letter IATA codes |
| `{YYYY-MM-DD}` | ISO date |
| `{cabin}` | `economy`, `business`, `first`, `premiumeconomy` |
| `{adults}-{children}-{infants}` | Passenger counts, all integers |

### Legacy JSX URL Format (query string)

```
/flight/availability?tripType=oneway
  &flightType=Economy
  &flights[0][origin]=NBO
  &flights[0][destination]=MBA
  &flights[0][depart]=2026-08-24
  &adults=1&children=0&infants=0
```

### How search context flows to BookingDetail

1. The search URL encodes all parameters (origin, destination, date, cabin, pax).
2. When a user selects an itinerary, `selectItinerary` serializes the normalized offer as JSON into `?offer=...` and also appends `adults`, `children`, `infants` as explicit query params.
3. `BookingDetail` reads the offer from `?offer=` and uses `rawOffer.passengers` (set by `MapOffer`) for pax counts, falling back to the explicit URL params.
4. The backend v2 controller (`Api/v2/FlightController::precisePricing`) receives the journeys reconstructed from `rawOffer.summary.legs` — the frontend always sends these so the backend never needs to look them up from cache.

---

## 🚀 Getting Started

### Prerequisites
- Node.js (v16+)
- PHP (v8.1+)
- Composer
- MySQL

