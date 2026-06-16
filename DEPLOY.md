# Deploy no VPS via GitHub

Arquitetura em produção (Ubuntu 22.04/24.04):

```
GitHub (push na main) ──> GitHub Actions ──> SSH no VPS ──> deploy/deploy.sh
                                                  │
VPS: nginx ──> api.SEU-DOMINIO.com  → Laravel (PHP 8.3-FPM + MySQL)
        └────> app.SEU-DOMINIO.com  → Nuxt SSR (Node + pm2, porta 3000)
```

## 1. Preparar o VPS (uma única vez)

```bash
# Pacotes
sudo apt update && sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd unzip git

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 + pm2
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
pm2 startup   # siga a instrução exibida para iniciar o pm2 no boot

# Banco
sudo mysql -e "CREATE DATABASE gestao_demandas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'gestao'@'localhost' IDENTIFIED BY 'SENHA-FORTE-AQUI';"
sudo mysql -e "GRANT ALL PRIVILEGES ON gestao_demandas.* TO 'gestao'@'localhost'; FLUSH PRIVILEGES;"
```

## 2. Clonar o projeto

```bash
sudo mkdir -p /var/www/gestao && sudo chown $USER:www-data /var/www/gestao
git clone git@github.com:SEU-USUARIO/gestao-demandas.git /var/www/gestao
# (configure uma deploy key no GitHub: Settings > Deploy keys, com a chave pública do VPS)
```

## 3. Configurar a API

```bash
cd /var/www/gestao/api
cp .env.example .env
nano .env
```

Valores importantes do `api/.env` em produção:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.SEU-DOMINIO.com

DB_CONNECTION=mysql
DB_DATABASE=gestao_demandas
DB_USERNAME=gestao
DB_PASSWORD=SENHA-FORTE-AQUI

SANCTUM_STATEFUL_DOMAINS=app.SEU-DOMINIO.com
SESSION_DOMAIN=.SEU-DOMINIO.com
SESSION_SECURE_COOKIE=true
FRONTEND_URL=https://app.SEU-DOMINIO.com

ADMIN_EMAIL=pauloguilherme.aformula@gmail.com
ADMIN_PASSWORD=TROQUE-AQUI
```

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 4. Configurar o front

```bash
cd /var/www/gestao/web
echo "NUXT_PUBLIC_API_BASE=https://api.SEU-DOMINIO.com/api" > .env
npm ci && npm run build
pm2 start .output/server/index.mjs --name gestao-web
pm2 save
```

> O Nuxt lê `NUXT_PUBLIC_API_BASE` em runtime; o pm2 herda o `.env` se você
> iniciar com `pm2 start ... --update-env` a partir da pasta, ou exporte a
> variável no ambiente do pm2 (`pm2 set` / ecosystem). Alternativa simples:
> `NUXT_PUBLIC_API_BASE=... pm2 start .output/server/index.mjs --name gestao-web`.

## 5. nginx + HTTPS

```bash
sudo cp /var/www/gestao/deploy/nginx-api.conf /etc/nginx/sites-available/gestao-api
sudo cp /var/www/gestao/deploy/nginx-web.conf /etc/nginx/sites-available/gestao-web
# edite os dois trocando SEU-DOMINIO.com
sudo ln -s /etc/nginx/sites-available/gestao-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/gestao-web /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# HTTPS (depois de apontar o DNS dos dois subdomínios para o IP do VPS)
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.SEU-DOMINIO.com -d app.SEU-DOMINIO.com
```

## 6. Deploy automático (GitHub Actions)

No repositório do GitHub: **Settings → Secrets and variables → Actions**, crie:

| Secret | Valor |
|---|---|
| `VPS_HOST` | IP ou hostname do VPS |
| `VPS_USER` | usuário SSH (ex.: `ubuntu`, `deploy`) |
| `VPS_SSH_KEY` | chave privada SSH (conteúdo completo do arquivo, ex.: `~/.ssh/id_ed25519`) |
| `VPS_PORT` | porta SSH (opcional, default 22) |

A partir daí, **todo push na `main` faz deploy sozinho** (`.github/workflows/deploy.yml`
→ executa `deploy/deploy.sh` no VPS: git pull, composer, migrate, build do Nuxt e
reload do pm2). Também dá para disparar manualmente na aba Actions (workflow_dispatch).

## Checklist final

- [ ] DNS: `api.` e `app.` apontando para o IP do VPS
- [ ] HTTPS ativo (Sanctum exige `SESSION_SECURE_COOKIE=true` em produção)
- [ ] `ADMIN_PASSWORD` trocada e `php artisan db:seed --force` rodado
- [ ] Link do formulário para os clientes: `https://app.SEU-DOMINIO.com/solicitar`
