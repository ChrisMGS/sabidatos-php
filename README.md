# Sabidatos para PHP y Laravel

Pone el asistente de Sabidatos dentro de su sistema. Cada persona le pregunta desde
su propia pantalla. El asistente sólo usa lo que su rol le permite.

## Instalar (Laravel)

Necesita el **código de instalación** que le da Trelintech desde su portal.
Vale 30 minutos y una sola vez.

```bash
composer require trelintech/sabidatos
php artisan sabidatos:install OPIA-XXXX-XXXX-XXXX-XXXX
```

El instalador:

1. Enseña lo que va a hacer y pide confirmación.
2. Canjea el código con Sabidatos. El secreto va directo a su `.env`; nadie lo ve.
3. Guarda antes una copia de su `.env`.
4. Envía a Sabidatos los **nombres** de sus roles. No envía usuarios ni datos.
5. Comprueba la conexión. Termina en `READY` o `NOT READY`.

Después, el botón del asistente aparece solo en las páginas de quien tiene
sesión. No hay que tocar plantillas ni compilar nada.

Luego Trelintech marca en su portal qué puede usar cada rol. Mientras un rol no
tenga nada marcado, su gente lee «No tienes acceso a esa información.»

## Comprobar

```bash
php artisan sabidatos:doctor
```

No cambia nada. Dice `READY` o `NOT READY`, y qué revisar si algo falla.

## Apagar y quitar

- **Apagar:** `SABIDATOS_ENABLED=false` en `.env` y `php artisan config:clear`. El
  sistema queda exactamente como antes de instalar.
- **Quitar:** además, `composer remove trelintech/sabidatos` y borrar las cuatro
  variables `SABIDATOS_*` del `.env`. No se toca ningún dato suyo.

## Qué hace y qué no

| Hace | No hace |
|---|---|
| Añade tres rutas bajo `/api/sabidatos`, con la sesión y el CSRF de su sistema | No crea tablas ni migraciones |
| Firma cada petición a Sabidatos desde su servidor | No da ninguna clave al navegador |
| Toma el usuario y sus roles de la sesión | No acepta del navegador usuario, roles ni permisos |
| Sirve el botón desde su propio dominio, con su huella SRI | No carga nada desde otros dominios |

**Roles:** si usa `spatie/laravel-permission`, se leen solos. Si no, indique en
`config/sabidatos.php` una clase que devuelva los roles de un usuario.

**Registros de pantalla:** si el asistente debe saber qué registro mira el
usuario (un pedido, una orden), declare el modelo en `config/sabidatos.php`
(`recursos`). Se autoriza con la Policy `view` del propio sistema: quien no puede
verlo, tampoco puede preguntar por él.

## Sin Laravel

El mismo paquete funciona en PHP sin framework:

```bash
composer require trelintech/sabidatos
vendor/bin/sabidatos install OPIA-XXXX-XXXX-XXXX-XXXX --env=/ruta/.env
```

Las tres rutas se conectan con el ejemplo de
`ejemplos/php-sin-framework/api-sabidatos.php`. Sólo hay que responder tres
preguntas con su propio código: quién es el usuario, qué roles tiene y si puede
ver un registro.

## Otros lenguajes

El contrato es el mismo para cualquier lenguaje. Trelintech le entrega la
especificación y comprueba la integración desde fuera con su verificador.

## Requisitos

- PHP 8.1 o superior, con `curl` y `json`.
- HTTPS en su sistema.
- Salida a internet hacia Sabidatos por HTTPS.

No hace falta root, Docker, Node ni procesos residentes: funciona en hosting
compartido.

## Límites conocidos

- **Hasta 10 roles por persona.** Si alguien tiene más, se envían los 10 primeros
  y queda un aviso en el log.
- **No excluya `api/sabidatos/*` del CSRF.** Las tres rutas usan el testigo de Laravel.
- **Caché de rutas:** `sabidatos:install` y `sabidatos:uninstall` la regeneran solas.
  Si cambia `SABIDATOS_ENABLED` a mano, ejecute `php artisan route:cache` de nuevo.
- **Octane, RoadRunner y FrankenPHP no están soportados** todavía: el chat
  necesita enviar la respuesta por partes.
- Un **proxy o PHP-FPM con un tiempo máximo corto** puede cortar respuestas largas.
- Con una **CSP con nonce** hay que permitir el script del botón.
