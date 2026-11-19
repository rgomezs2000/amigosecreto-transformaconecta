# Límite de solicitudes de registro

El registro público tiene un límite anti-spam de **10 solicitudes por minuto
por IP**. La consulta de disponibilidad del usuario tiene 60 por minuto.

Si se supera el límite, el sistema responde `429` con un mensaje entendible:

> Has realizado varios intentos de registro seguidos. Espera 60 segundos antes
> de volver a intentarlo.

El botón **Solicitar registro** se deshabilita mientras se envía la solicitud,
para evitar envíos duplicados por doble clic.

## Implementación

- [web.php](../routes/web.php): límites `throttle:10,1` y `throttle:60,1`.
- [app.php](../bootstrap/app.php): convierte el `ThrottleRequestsException` en
  un mensaje en español para las rutas públicas.
- [login.blade.php](../resources/views/auth/login.blade.php): bloqueo del botón
  durante el envío y visualización del mensaje devuelto.
