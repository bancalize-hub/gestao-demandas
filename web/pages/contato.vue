<script setup lang="ts">
definePageMeta({ middleware: ['sanctum:auth'] })
</script>

<template>
  <CrmScreenContact />
</template>
