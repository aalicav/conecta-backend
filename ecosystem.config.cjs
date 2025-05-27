module.exports = {
  apps: [{
    name: "conecta-backend-queue",
    script: "artisan",
    interpreter: "php",
    args: "queue:work --sleep=3 --tries=3 --timeout=90",
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: "512M",
    env: {
      NODE_ENV: "development"
    },
    env_production: {
      NODE_ENV: "production"
    }
  }]
} 