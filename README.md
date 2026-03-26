# OWWA Web-Based Data-Driven Inventory System with Procurement Analysis

A centralized, web-based inventory system for OWWA Regional Office IV-A, with procurement analytics, RAG-powered recommendations, and COA-compliant reports.

## Stack

- **Laravel** 12
- **Filament** 5 (admin UI: Livewire + Tailwind)
- **MySQL** (schema manageable via MySQL Workbench; Laravel migrations included)
- **Ollama** (DeepSeek/Neuron) for RAG and procurement recommendations
- **Cloudflare Tunnel** for secure access (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md))

## Requirements

- PHP 8.2+ (with extensions: pdo_mysql, mbstring, xml, curl, etc.; **ext-intl** required for Filament)
- Composer
- MySQL 8 (or MariaDB)
- Node.js/npm (for Vite; optional if using pre-built assets)

## Features

- **Setup**: Offices, Departments, Item categories, Items (with reorder levels)
- **Inventory**: Acquisitions, Issuances, Transfers, Disposals (with auto reference codes)
- **Requisitions**: Create requisitions with line items; Supply Custodian can Approve/Reject
- **Dashboard**: Low-stock alerts widget, Issuance trends chart
- **Analytics**: Procurement recommendations (RAG via Ollama), COA reports (PDF)
- **Roles**: Supply Custodian (full access), Employee (request + view)
