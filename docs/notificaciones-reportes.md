# Notificaciones de reportes

Cada caso avisa según su fase. El **beneficiario** recibe correo y push de cada
cambio de fase; el **administrador** que lo gestiona recibe el correo del caso.

## Al abrir el ticket (por atender)

Cuando alguien reporta un mensaje, a un usuario o una conversación/grupo:

1. Se abre el **chat del caso en solo lectura**, solo con el beneficiario.
2. El **beneficiario** recibe un correo de apertura (ticket, motivo, fecha y
   aviso de que el chat está en solo lectura) y un push.
3. **Moderación**: el reporte aparece en la tabla.
4. La **administración** recibe un push del nuevo reporte (sin correo todavía).

## Al tomar el caso (atendiendo)

Cuando un administrador pulsa **Tomar caso**:

1. El administrador **entra al chat del caso** y la escritura se habilita para el
   beneficiario y el gestor.
2. El administrador recibe el **correo del caso para gestionarlo**: ticket,
   reportado por, autor del mensaje, conversación, motivo y fecha.
3. El **beneficiario** recibe correo y push de la nueva fase, con el enlace al
   chat ya habilitado.

## Al remitir el caso

Cuando el administrador que lo gestiona lo remite a otro:

1. El **nuevo gestor** recibe el correo del caso con la observación de la
   remisión y un push.
2. El **beneficiario** recibe correo y push del cambio de gestor; el chat sigue
   habilitado porque el caso continúa en atención.
3. La remisión queda en el historial de la ficha (con quién, a quién y el
   motivo).

## Al cerrar el caso (cerrado)

1. El chat del caso pasa a **solo lectura** para ambos.
2. El **beneficiario** recibe el correo y push de cierre, con la observación de
   cierre si la hay.

## Evitar duplicados

Los reportes de usuario y de conversación usan `firstOrCreate`: si la misma
persona vuelve a reportar lo mismo, no se duplican ni el ticket ni los avisos.

Los avisos push respetan las preferencias de cada usuario (sonido, avisos
visuales y control global). Los correos se registran en `email_logs` y en
Firestore (`correos`); si el correo global está desactivado, quedan como
«skipped».
