<script setup lang="ts">
import BillingApp from '../BillingAndPayments/BillingApp.vue';
import type {Session} from '../FleetAndDocuments/contracts';
defineProps<{session:Session|null}>();
const emit=defineEmits<{denied:[error:unknown];lock:[locked:boolean];session:[actor:Session]}>();
</script>
<template><BillingApp :session="session" @denied="emit('denied',$event)" @lock="emit('lock',$event)" @session="emit('session',$event)"/></template>
