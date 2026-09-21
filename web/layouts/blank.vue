<template>
  <!--
    Layout das telas sem navegação (login/cadastro).

    `min-height` em vez de `height` fixo, e rolagem liberada: o valor antigo era
    `height:100vh; min-height:760px; overflow:hidden`, que cortava o cartão em
    qualquer celular com menos de 760px de altura útil — e com o teclado aberto
    sobra bem menos que isso. `100dvh` acompanha a barra do navegador móvel.
  -->
  <div style="display:flex;width:100%;min-height:100dvh;background:var(--c-bg-deep);color:var(--c-text);font-family:'Manrope',sans-serif;overflow-x:hidden;overflow-y:auto;">
    <slot />
  </div>
</template>
