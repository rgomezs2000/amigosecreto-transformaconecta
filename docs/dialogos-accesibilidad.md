# Accesibilidad de los diálogos Bootbox

Chrome muestra este aviso cuando un modal se oculta mientras el foco sigue en
uno de sus botones:

> Blocked aria-hidden on an element because its descendant retained focus...

Bootbox/Bootstrap pone `aria-hidden="true"` al ocultar el diálogo, pero el foco
todavía está en `.bootbox-accept`. La solución es retirar el foco **antes** de
que se aplique `aria-hidden`.

En [dialogos.js](../public/js/dialogos.js) se escucha `hide.bs.modal`:

- si el modal es de Bootbox y el elemento activo está dentro, se llama a
  `blur()` antes de que Bootstrap oculte el diálogo;
- se cubren tanto eventos nativos de Bootstrap 5 como el evento jQuery;
- aplica a confirmaciones, alertas, prompts y diálogos personalizados.

Con esto el foco queda en el `body` al ocultar el diálogo y Chrome deja de
bloquear el `aria-hidden`.
