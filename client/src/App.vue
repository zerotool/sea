<template>
  <div>
    <div class="info-panel">
      <h1>Sea Battle Prototype</h1>
      <div>Current Sector: {{ sectorLabel }}</div>
      <div class="secondary">Player: {{ playerId }}</div>
      <div class="secondary">Ship (world): {{ coords }}</div>
      <div class="secondary" v-if="statusMessage">{{ statusMessage }}</div>
    </div>
    <SeaCanvas
      :ws-base="wsBase"
      @sector-updated="handleSectorUpdate"
      @coords-updated="handleCoordsUpdate"
      @status="statusMessage = $event"
      @player-id="playerId = $event"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import SeaCanvas from './components/SeaCanvas.vue';

const sectorLabel = ref('Loading...');
const coords = ref('0.0, 0.0');
const statusMessage = ref('');
const playerId = ref(localStorage.getItem('sea-player-id') || 'assigning…');

import { appConfig } from './config';

const wsBase = appConfig.wsBase;

function handleSectorUpdate(value) {
  sectorLabel.value = value;
}

function handleCoordsUpdate(value) {
  coords.value = value;
}
</script>
