# Provisionamento manual

O cadastro publico de empresas foi desativado.

Para criar ou atualizar manualmente uma empresa e seu usuario administrador:

```powershell
php scripts/create_company_admin.php --company-name="Empresa X LTDA" --admin-name="Administrador" --admin-email="admin@empresax.com" --admin-password="SenhaForte123!"
```

Opcoes uteis:

```powershell
php scripts/create_company_admin.php --help
php scripts/create_company_admin.php --company-name="Empresa X LTDA" --trade-name="Empresa X" --slug="empresa-x" --admin-name="Administrador" --admin-email="admin@empresax.com" --admin-password="SenhaForte123!" --role="ADMIN"
```

Observacoes:
- O login atual usa apenas e-mail e senha, sem seletor de empresa.
- Por isso, cada e-mail precisa ser unico no sistema.
- O script permite atualizar empresa e usuario existentes no mesmo comando.
