<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:#0b141a;">
    <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
      <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #1c2730;">
        <div>
          <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Agenda</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">15 – 19 de Junho, 2026</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <div style="display:flex;background:#202c33;border-radius:10px;overflow:hidden;"><button class="seg" style="border:none;background:transparent;color:#8696a0;padding:8px 11px;cursor:pointer;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 6-6 6 6 6" /></svg></button><button class="seg" style="border:none;background:transparent;color:#8696a0;padding:8px 11px;cursor:pointer;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6" /></svg></button></div>
          <div style="display:flex;background:#202c33;border-radius:10px;padding:3px;"><span style="font-size:12.5px;font-weight:700;color:#062014;background:#25D366;padding:6px 13px;border-radius:8px;">Semana</span><span style="font-size:12.5px;font-weight:600;color:#8696a0;padding:6px 13px;cursor:pointer;">Dia</span><span style="font-size:12.5px;font-weight:600;color:#8696a0;padding:6px 13px;cursor:pointer;">Mês</span></div>
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Evento</button>
        </div>
      </div>

      <div style="flex:1;overflow:auto;padding:0 30px 24px;">
        <div style="display:grid;grid-template-columns:54px repeat(5,1fr);position:sticky;top:0;background:#0b141a;z-index:2;padding-top:14px;">
          <div />
          <div style="text-align:center;padding-bottom:12px;"><div style="font-size:11.5px;color:#8696a0;">SEG</div><div style="font-size:18px;font-weight:700;margin-top:2px;">15</div></div>
          <div style="text-align:center;padding-bottom:12px;"><div style="font-size:11.5px;color:#8696a0;">TER</div><div style="font-size:18px;font-weight:700;margin-top:2px;">16</div></div>
          <div style="text-align:center;padding-bottom:12px;"><div style="font-size:11.5px;color:#25D366;font-weight:700;">QUA</div><div style="width:34px;height:34px;border-radius:50%;background:#25D366;color:#062014;font-size:17px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:2px auto 0;">17</div></div>
          <div style="text-align:center;padding-bottom:12px;"><div style="font-size:11.5px;color:#8696a0;">QUI</div><div style="font-size:18px;font-weight:700;margin-top:2px;">18</div></div>
          <div style="text-align:center;padding-bottom:12px;"><div style="font-size:11.5px;color:#8696a0;">SEX</div><div style="font-size:18px;font-weight:700;margin-top:2px;">19</div></div>
        </div>

        <div style="display:grid;grid-template-columns:54px repeat(5,1fr);">
          <div style="font-size:11px;color:#8696a0;text-align:right;padding:0 10px;height:74px;border-top:1px solid #16222a;">09:00</div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div style="background:rgba(83,189,235,.16);border-left:3px solid #53bdeb;border-radius:7px;padding:7px 9px;height:100%;"><div style="font-size:12px;font-weight:700;color:#9fdcf5;">Prospecção fria</div><div style="font-size:10.5px;color:#7fa9bd;margin-top:1px;">09:00 – 09:45</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div style="background:rgba(124,108,245,.16);border-left:3px solid #7c6cf5;border-radius:7px;padding:7px 9px;height:100%;"><div style="font-size:12px;font-weight:700;color:#c0b6f9;">Follow-up Pedro</div><div style="font-size:10.5px;color:#9b90c7;margin-top:1px;">09:00 – 09:30</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />

          <div style="font-size:11px;color:#8696a0;text-align:right;padding:0 10px;height:74px;border-top:1px solid #16222a;">11:00</div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div style="background:rgba(255,180,67,.16);border-left:3px solid #ffb443;border-radius:7px;padding:7px 9px;height:100%;"><div style="font-size:12px;font-weight:700;color:#ffd494;">Construtora Vértice</div><div style="font-size:10.5px;color:#c7a86a;margin-top:1px;">11:00 – 11:30</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />

          <div style="font-size:11px;color:#8696a0;text-align:right;padding:0 10px;height:74px;border-top:1px solid #16222a;">14:00</div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div class="liveev" style="background:#1a3a2c;border-left:3px solid #25D366;border-radius:7px;padding:7px 9px;height:100%;cursor:pointer;position:relative;" @click="navigateTo('/reuniao/sala')"><div style="display:flex;align-items:center;gap:4px;"><span style="width:7px;height:7px;border-radius:50%;background:#25D366;animation:recpulse 1.6s infinite;" /><span style="font-size:12px;font-weight:700;color:#7ee6a8;">Demo · Mariana</span></div><div style="font-size:10.5px;color:#5fb585;margin-top:1px;">14:00 – 14:20 · ao vivo</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />

          <div style="font-size:11px;color:#8696a0;text-align:right;padding:0 10px;height:74px;border-top:1px solid #16222a;">16:00</div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div style="background:rgba(124,108,245,.16);border-left:3px solid #7c6cf5;border-radius:7px;padding:7px 9px;height:100%;"><div style="font-size:12px;font-weight:700;color:#c0b6f9;">Reunião de time</div><div style="font-size:10.5px;color:#9b90c7;margin-top:1px;">16:00 – 16:30</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;padding:4px;"><div style="background:rgba(83,189,235,.16);border-left:3px solid #53bdeb;border-radius:7px;padding:7px 9px;height:100%;"><div style="font-size:12px;font-weight:700;color:#9fdcf5;">Onboarding Juliana</div><div style="font-size:10.5px;color:#7fa9bd;margin-top:1px;">16:00 – 17:00</div></div></div>
          <div style="border-top:1px solid #16222a;border-left:1px solid #16222a;" />
        </div>
      </div>
    </div>

    <div style="width:300px;flex-shrink:0;background:#111b21;border-left:1px solid #1c2730;display:flex;flex-direction:column;">
      <div style="padding:20px 20px 14px;border-bottom:1px solid #1c2730;"><div style="font-weight:700;font-size:16px;">Hoje · 4 reuniões</div><div style="font-size:12.5px;color:#8696a0;margin-top:2px;">Quarta, 17 de Junho</div></div>
      <div style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:11px;">
        <div style="background:#1a3a2c;border:1px solid rgba(37,211,102,.3);border-radius:13px;padding:14px;">
          <div style="display:flex;align-items:center;gap:6px;"><span style="width:7px;height:7px;border-radius:50%;background:#25D366;animation:recpulse 1.6s infinite;" /><span style="font-size:11px;font-weight:700;color:#25D366;">AGORA · AO VIVO</span></div>
          <div style="font-weight:700;font-size:14.5px;margin-top:8px;">Demonstração — Mariana Costa</div>
          <div style="font-size:12.5px;color:#8696a0;margin-top:3px;">14:00 – 14:20 · Vídeo + tela</div>
          <button class="wabtn" style="width:100%;margin-top:11px;background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px;border-radius:9px;cursor:pointer;" @click="navigateTo('/reuniao/sala')">Entrar na reunião</button>
        </div>
        <div style="background:#202c33;border-radius:13px;padding:14px;"><div style="font-size:11px;font-weight:700;color:#8696a0;">16:00</div><div style="font-weight:700;font-size:14px;margin-top:6px;">Reunião de time comercial</div><div style="font-size:12px;color:#8696a0;margin-top:3px;">6 participantes</div></div>
        <div style="background:#202c33;border-radius:13px;padding:14px;"><div style="font-size:11px;font-weight:700;color:#8696a0;">16:00</div><div style="font-weight:700;font-size:14px;margin-top:6px;">Onboarding — Juliana Mendes</div><div style="font-size:12px;color:#8696a0;margin-top:3px;">Cliente fechado · 1h</div></div>
        <div style="background:#202c33;border-radius:13px;padding:14px;"><div style="font-size:11px;font-weight:700;color:#8696a0;">17:30</div><div style="font-weight:700;font-size:14px;margin-top:6px;">Follow-up — Café Aurora</div><div style="font-size:12px;color:#8696a0;margin-top:3px;">Ligação rápida</div></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.seg:hover { background: #2a3942 !important; }
.liveev:hover { background: #1f4836 !important; }
</style>
