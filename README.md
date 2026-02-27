# City085 Monitor Beta 🏙

**Monitor urbano de Fortaleza** — Termômetro digital da cidade.

## O que é?

Uma plataforma pública (sem login) que monitora o pulso de Fortaleza em tempo real, através de:

- **🤖 Chat com IA** — Converse sobre qualquer assunto da cidade usando o CityBot 085 (Groq/LLaMA)
- **🔥 Trending Topics** — Tópicos extraídos automaticamente das conversas com IA
- **🗺 Mapa Interativo** — Eventos e tópicos georreferenciados por bairro (Leaflet)
- **📁 Dados Públicos** — Integração com o Portal de Dados Abertos de Fortaleza (CKAN)
- **📊 Dashboard** — Visão geral com stats, sentimento, categorias e bairros em destaque

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Frontend | React 18 + Vite + Zustand |
| Backend | PHP 8.x + MariaDB |
| IA | Groq API (LLaMA 3.3 70B) |
| Mapa | Leaflet + CartoDB Dark Matter |
| Charts | Recharts |
| Dados | CKAN API (dados.fortaleza.ce.gov.br) |

## Estrutura

```
city085-monitor-beta/
├── backend/
│   ├── index.php              # API Router
│   ├── .htaccess               # Rewrite rules
│   ├── controllers/
│   │   ├── ChatController.php       # Chat + extração de tópicos
│   │   ├── TrendingController.php   # Trending topics
│   │   ├── DataController.php       # Dados públicos
│   │   ├── EventsController.php     # Eventos
│   │   └── StatsController.php      # Estatísticas
│   ├── services/
│   │   ├── GroqCityService.php      # IA + NLP urbano
│   │   └── OpenDataService.php      # Integração CKAN
│   └── setup/
│       └── schema.sql               # Schema do banco
├── frontend/
│   ├── src/
│   │   ├── App.jsx
│   │   ├── components/
│   │   │   ├── Chat/CityChat.jsx
│   │   │   ├── Dashboard/
│   │   │   │   ├── DashboardLayout.jsx
│   │   │   │   ├── CityMap.jsx
│   │   │   │   ├── TrendingTopics.jsx
│   │   │   │   ├── EventsFeed.jsx
│   │   │   │   ├── StatsOverview.jsx
│   │   │   │   └── DataExplorer.jsx
│   │   │   └── UI/
│   │   │       ├── Header.jsx
│   │   │       ├── Sidebar.jsx
│   │   │       └── LoadingScreen.jsx
│   │   ├── services/api.js
│   │   ├── store/useStore.js
│   │   └── utils/categories.js
│   ├── package.json
│   └── vite.config.js
```

## Setup

### 1. Banco de dados
```sql
-- Executar no phpMyAdmin ou terminal
source backend/setup/schema.sql
```

### 2. Variáveis de ambiente
O projeto reutiliza o `.env` do portfólio principal (GROQ_API_KEY).

### 3. Frontend
```bash
cd frontend
npm install
npm run dev    # Dev server: http://localhost:5174
npm run build  # Build para produção
```

### 4. Backend
Apontar o virtual host / proxy para `backend/index.php`.

## Fontes de Dados

- [Portal de Dados Abertos de Fortaleza](https://dados.fortaleza.ce.gov.br/)
- [Portal da Transparência](https://transparencia.fortaleza.ce.gov.br/)

## Categorias

| Ícone | Categoria | Cor |
|-------|-----------|-----|
| 🔒 | Segurança | #ff4444 |
| 🚦 | Trânsito | #ff8c00 |
| 🏥 | Saúde | #00cc88 |
| 📚 | Educação | #4a9eff |
| 🎉 | Eventos | #ff69b4 |
| 🏛 | Política | #9966ff |
| 🌤 | Clima | #00bcd4 |
| 🏗 | Infraestrutura | #ffeb3b |
| 🎭 | Cultura | #e91e63 |
| 💰 | Economia | #4caf50 |
| 🌿 | Meio Ambiente | #2e7d32 |
| ⚽ | Esporte | #ff5722 |
| 💻 | Tecnologia | #00e5ff |
| 🏢 | Serviços Públicos | #607d8b |

## Licença

Projeto pessoal de portfólio — [@mikaeldmts](https://github.com/mikaeldmts)
