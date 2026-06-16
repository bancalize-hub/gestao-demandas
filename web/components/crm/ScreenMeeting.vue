<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:#06090b;">
    <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:15px 24px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <span style="display:flex;align-items:center;gap:6px;background:rgba(255,77,77,.15);color:#ff6b6b;font-size:12px;font-weight:700;padding:5px 11px;border-radius:8px;"><span style="width:8px;height:8px;border-radius:50%;background:#ff4d4d;animation:recpulse 1.4s infinite;" />GRAVANDO</span>
          <div><div style="font-weight:700;font-size:15px;">Demonstração — Vértice Pro</div><div style="font-size:12px;color:#8696a0;">14:08 · 3 participantes</div></div>
        </div>
        <button class="ghost" style="display:flex;align-items:center;gap:7px;background:#1a262e;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:10px;cursor:pointer;" @click="crm.go('contact')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" stroke-linecap="round" /></svg>Ficha do lead</button>
      </div>

      <div style="flex:1;padding:0 24px;display:flex;flex-direction:column;min-height:0;">
        <div style="flex:1;border-radius:16px;overflow:hidden;background:#11161b;border:1px solid #1c2730;display:flex;flex-direction:column;position:relative;min-height:0;">
          <div style="position:absolute;top:14px;left:14px;z-index:3;display:flex;align-items:center;gap:7px;background:rgba(6,9,11,.7);backdrop-filter:blur(6px);padding:6px 12px;border-radius:9px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2"><rect x="2.5" y="4" width="19" height="13" rx="2" /><path d="M8 21h8M12 17v4" stroke-linecap="round" /></svg><span style="font-size:12px;font-weight:600;color:#cfe9da;">Ana está compartilhando a tela</span></div>

          <div style="flex:1;background:#f4f6fa;color:#1a2330;padding:46px 56px;display:flex;flex-direction:column;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:10px;"><div style="width:30px;height:30px;border-radius:8px;background:#25D366;display:flex;align-items:center;justify-content:center;"><svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg></div><span style="font-weight:800;font-size:16px;color:#0d2b1c;">Vértice Pro</span></div>
              <span style="font-size:13px;color:#7a8696;font-weight:600;">Proposta comercial · 2026</span>
            </div>
            <div style="font-size:34px;font-weight:800;letter-spacing:-.5px;margin-top:38px;line-height:1.15;">Crescimento previsível<br>para sua equipe de vendas</div>
            <div style="display:flex;gap:18px;margin-top:auto;align-items:flex-end;">
              <div style="display:flex;align-items:flex-end;gap:10px;height:130px;"><div style="width:38px;height:46%;background:#cfe6d8;border-radius:6px 6px 0 0;" /><div style="width:38px;height:62%;background:#a3d8ba;border-radius:6px 6px 0 0;" /><div style="width:38px;height:78%;background:#5fc78e;border-radius:6px 6px 0 0;" /><div style="width:38px;height:100%;background:#25D366;border-radius:6px 6px 0 0;" /></div>
              <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:14px;"><div style="background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:15px 17px;"><div style="font-size:12px;color:#7a8696;">Conversão média</div><div style="font-size:26px;font-weight:800;color:#0d2b1c;margin-top:3px;">+34%</div></div><div style="background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:15px 17px;"><div style="font-size:12px;color:#7a8696;">Tempo de resposta</div><div style="font-size:26px;font-weight:800;color:#0d2b1c;margin-top:3px;">−61%</div></div></div>
            </div>
          </div>

          <div style="position:absolute;bottom:14px;right:14px;display:flex;gap:10px;">
            <div style="width:148px;height:90px;border-radius:11px;background:linear-gradient(135deg,#1f3a2b,#0e2419);border:2px solid #25D366;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;"><div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#25D366,#0e8a4f);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px;color:#062014;">AB</div><span style="position:absolute;bottom:6px;left:8px;font-size:10.5px;font-weight:600;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.6);">Você (Ana)</span></div>
            <div style="width:148px;height:90px;border-radius:11px;background:linear-gradient(135deg,#3a2330,#1f1219);border:1px solid #2a3942;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;"><div style="width:46px;height:46px;border-radius:50%;background:#b5446e;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px;">MC</div><span style="position:absolute;bottom:6px;left:8px;font-size:10.5px;font-weight:600;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.6);">Mariana Costa</span><span style="position:absolute;bottom:6px;right:8px;width:20px;height:20px;border-radius:50%;background:rgba(255,77,77,.85);display:flex;align-items:center;justify-content:center;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M1 1l22 22M9 9v2a3 3 0 0 0 5 2.1M15 9.3V6a3 3 0 0 0-5.7-1.3" /></svg></span></div>
          </div>
        </div>

        <div style="display:flex;align-items:center;justify-content:center;gap:13px;padding:18px 0 20px;">
          <button class="mctrl" style="width:52px;height:52px;border-radius:16px;border:none;background:#1a262e;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3Z" /><path d="M19 11a7 7 0 0 1-14 0M12 18v3" stroke-linecap="round" /></svg></button>
          <button class="mctrl" style="width:52px;height:52px;border-radius:16px;border:none;background:#1a262e;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" /></svg></button>
          <button class="wabtn" style="width:52px;height:52px;border-radius:16px;border:none;background:#25D366;color:#062014;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 16px rgba(37,211,102,.3);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="4" width="19" height="13" rx="2" /><path d="M8 21h8M12 17v4" stroke-linecap="round" /></svg></button>
          <button class="mctrl" style="width:52px;height:52px;border-radius:16px;border:none;background:#1a262e;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.34 9 9 0 0 1-3.9-.9L3 21l1.06-4.1A8.38 8.38 0 0 1 3 11.5 8.5 8.5 0 0 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          <button class="mctrl" style="width:52px;height:52px;border-radius:16px;border:none;background:#1a262e;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0 1 12 0M16 5.3a3 3 0 0 1 0 5.4M21 20a6 6 0 0 0-4-5.6" stroke-linecap="round" /></svg></button>
          <button class="leave" style="display:flex;align-items:center;gap:8px;height:52px;padding:0 22px;border-radius:16px;border:none;background:#ff4d4d;color:#fff;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;margin-left:6px;" @click="crm.go('chat')"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M21 15.5c-1.2 0-2.4-.2-3.6-.6a1 1 0 0 0-1 .25l-2 2a15 15 0 0 1-6.6-6.6l2-2a1 1 0 0 0 .25-1A11 11 0 0 1 9.5 3 1 1 0 0 0 8.5 2H5a1 1 0 0 0-1 1 18 18 0 0 0 18 18 1 1 0 0 0 1-1v-3.5a1 1 0 0 0-1-1Z" transform="rotate(135 12 12)" /></svg>Sair</button>
        </div>
      </div>
    </div>

    <div style="width:312px;flex-shrink:0;background:#0d141a;border-left:1px solid #1c2730;display:flex;flex-direction:column;">
      <div style="padding:18px 20px;border-bottom:1px solid #1c2730;display:flex;align-items:center;gap:11px;">
        <div style="width:42px;height:42px;border-radius:50%;background:#b5446e;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;">MC</div>
        <div><div style="font-weight:700;font-size:14.5px;">Mariana Costa</div><div style="font-size:12px;color:#25D366;">🔥 Lead quente · R$ 4.200/mês</div></div>
      </div>
      <div style="padding:16px 20px;border-bottom:1px solid #1c2730;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:11px;">
          <div style="display:flex;align-items:center;gap:7px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="#7c6cf5"><path d="m12 2 2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z" /></svg><span style="font-size:11px;font-weight:700;color:#a89bf9;letter-spacing:.5px;">IA · NOTAS AUTOMÁTICAS</span></div>
          <span style="display:flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;color:#7c6cf5;"><span style="width:6px;height:6px;border-radius:50%;background:#7c6cf5;animation:recpulse 1.4s infinite;" />ouvindo</span>
        </div>
        <div style="background:rgba(124,108,245,.08);border:1px solid rgba(124,108,245,.25);border-radius:12px;padding:13px;">
          <div style="font-size:10.5px;font-weight:700;color:#8696a0;letter-spacing:.4px;margin-bottom:10px;">PONTOS-CHAVE DETECTADOS</div>
          <div style="display:flex;flex-direction:column;gap:9px;">
            <div style="display:flex;gap:8px;font-size:12.5px;color:#d7d2f5;line-height:1.35;"><span style="color:#7c6cf5;">●</span>Equipe de 12 pessoas no comercial</div>
            <div style="display:flex;gap:8px;font-size:12.5px;color:#d7d2f5;line-height:1.35;"><span style="color:#7c6cf5;">●</span>Precisa de integração com WhatsApp</div>
            <div style="display:flex;gap:8px;font-size:12.5px;color:#d7d2f5;line-height:1.35;"><span style="color:#7c6cf5;">●</span>Decisão de compra até sexta-feira</div>
            <div style="display:flex;gap:8px;font-size:12.5px;color:#d7d2f5;line-height:1.35;"><span style="color:#25D366;">✓</span><span><b style="color:#7ee6a8;">Ação:</b> enviar contrato após a demo</span></div>
          </div>
          <button class="aibtn" style="width:100%;margin-top:13px;background:#7c6cf5;border:none;color:#fff;font-family:inherit;font-size:12.5px;font-weight:700;padding:9px;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Salvar no CRM</button>
        </div>
        <div style="font-size:10.5px;font-weight:700;color:#8696a0;letter-spacing:.4px;margin:16px 0 9px;">TRANSCRIÇÃO AO VIVO</div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div style="font-size:12.5px;color:#aebac1;line-height:1.4;"><span style="font-weight:700;color:#e57aa0;">Mariana:</span> A integração com o WhatsApp já vem inclusa no Pro?</div>
          <div style="font-size:12.5px;color:#aebac1;line-height:1.4;"><span style="font-weight:700;color:#25D366;">Ana:</span> Vem sim, e a gente configura junto no onboarding.</div>
        </div>
      </div>
      <div style="flex:1;display:flex;flex-direction:column;min-height:0;">
        <div style="padding:14px 20px 8px;font-size:11px;font-weight:700;color:#8696a0;letter-spacing:.5px;">CHAT DA REUNIÃO</div>
        <div style="flex:1;overflow-y:auto;padding:6px 20px;display:flex;flex-direction:column;gap:11px;">
          <div><div style="font-size:11px;color:#7c6cf5;font-weight:700;">Mariana</div><div style="font-size:13px;color:#cdd6db;margin-top:2px;">Esse gráfico é com dados reais?</div></div>
          <div><div style="font-size:11px;color:#25D366;font-weight:700;">Você</div><div style="font-size:13px;color:#cdd6db;margin-top:2px;">Sim! Média dos nossos clientes do setor 👍</div></div>
        </div>
        <div style="padding:12px 16px;border-top:1px solid #1c2730;"><div style="display:flex;align-items:center;gap:8px;background:#202c33;border-radius:11px;padding:8px 13px;"><input placeholder="Mensagem para todos" style="flex:1;background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366"><path d="M3.4 20.4 21.85 12 3.4 3.6l.05 6.53L17 12 3.45 13.87z" /></svg></div></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ghost:hover { background: #202c33 !important; }
.mctrl:hover { background: #202c33 !important; }
.wabtn:hover { background: #2ee070 !important; }
.leave:hover { background: #ff6363 !important; }
.aibtn:hover { background: #8b7cff !important; }
</style>
