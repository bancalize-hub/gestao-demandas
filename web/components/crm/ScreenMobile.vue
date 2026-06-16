<script setup lang="ts">
// Tela estática: dois frames de celular (caixa de entrada + conversa).
</script>

<template>
  <div style="flex:1;min-width:0;background:#0a0f13;display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:24px 32px 6px;">
      <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">App do atendente</div>
      <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">O mesmo CRM no celular — atenda de qualquer lugar</div>
    </div>
    <div style="flex:1;display:flex;align-items:center;justify-content:center;gap:46px;padding:18px 32px 40px;flex-wrap:wrap;">

      <div style="display:flex;flex-direction:column;align-items:center;gap:14px;">
        <span style="font-size:12px;font-weight:600;color:#8696a0;">Caixa de entrada</span>
        <div style="width:292px;height:600px;border-radius:42px;background:#06090b;padding:11px;box-shadow:0 30px 70px rgba(0,0,0,.5);border:1px solid #1c2730;">
          <div style="width:100%;height:100%;border-radius:32px;overflow:hidden;background:#0b141a;display:flex;flex-direction:column;position:relative;">
            <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:120px;height:26px;background:#06090b;border-radius:0 0 16px 16px;z-index:5;" />
            <div style="background:#202c33;padding:34px 16px 12px;">
              <div style="display:flex;align-items:center;justify-content:space-between;"><span style="font-size:19px;font-weight:800;color:#e9edef;">Conversas</span><div style="display:flex;gap:14px;color:#8696a0;"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" /></svg></div></div>
            </div>
            <div style="flex:1;overflow-y:auto;">
              <div style="display:flex;gap:11px;padding:13px 15px;align-items:center;background:#101c22;"><div style="width:46px;height:46px;border-radius:50%;background:#b5446e;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0;">MC</div><div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;"><span style="font-weight:700;font-size:14px;">Mariana Costa</span><span style="font-size:10.5px;color:#25D366;font-weight:600;">09:22</span></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;"><span style="font-size:12.5px;color:#aebac1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;">Pode enviar a proposta 🙌</span><span style="background:#25D366;color:#062014;font-size:10.5px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;">2</span></div></div></div>
              <div style="display:flex;gap:11px;padding:13px 15px;align-items:center;"><div style="width:46px;height:46px;border-radius:50%;background:#3b6ea5;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0;">RA</div><div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;"><span style="font-weight:600;font-size:14px;">Ricardo Alves</span><span style="font-size:10.5px;color:#8696a0;">09:05</span></div><div style="font-size:12.5px;color:#8696a0;margin-top:3px;">🎤 Áudio · 0:34</div></div></div>
              <div style="display:flex;gap:11px;padding:13px 15px;align-items:center;"><div style="width:46px;height:46px;border-radius:50%;background:#5b6470;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;">CV</div><div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;"><span style="font-weight:600;font-size:14px;">Construtora Vértice</span><span style="font-size:10.5px;color:#25D366;font-weight:600;">08:47</span></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;"><span style="font-size:12.5px;color:#aebac1;">Qual o prazo de entrega?</span><span style="background:#25D366;color:#062014;font-size:10.5px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;">1</span></div></div></div>
              <div style="display:flex;gap:11px;padding:13px 15px;align-items:center;"><div style="width:46px;height:46px;border-radius:50%;background:#8e5bb5;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0;">JM</div><div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;"><span style="font-weight:600;font-size:14px;">Juliana Mendes</span><span style="font-size:10.5px;color:#8696a0;">Ontem</span></div><div style="font-size:12.5px;color:#25D366;font-style:italic;margin-top:3px;">digitando…</div></div></div>
              <div style="display:flex;gap:11px;padding:13px 15px;align-items:center;"><div style="width:46px;height:46px;border-radius:50%;background:#4a8f7b;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0;">FL</div><div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;"><span style="font-weight:600;font-size:14px;">Fernanda Lima</span><span style="font-size:10.5px;color:#8696a0;">Seg</span></div><div style="font-size:12.5px;color:#8696a0;margin-top:3px;">Obrigada pelo atendimento!</div></div></div>
            </div>
            <div style="position:absolute;bottom:18px;right:16px;width:52px;height:52px;border-radius:17px;background:#25D366;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 20px rgba(37,211,102,.4);"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#062014" stroke-width="2.2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></div>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;align-items:center;gap:14px;">
        <span style="font-size:12px;font-weight:600;color:#8696a0;">Conversa</span>
        <div style="width:292px;height:600px;border-radius:42px;background:#06090b;padding:11px;box-shadow:0 30px 70px rgba(0,0,0,.5);border:1px solid #1c2730;">
          <div style="width:100%;height:100%;border-radius:32px;overflow:hidden;background:#0b141a;background-image:radial-gradient(circle at 30% 20%,rgba(37,211,102,.05),transparent 45%);display:flex;flex-direction:column;position:relative;">
            <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:120px;height:26px;background:#06090b;border-radius:0 0 16px 16px;z-index:5;" />
            <div style="background:#202c33;padding:32px 14px 11px;display:flex;align-items:center;gap:10px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2"><path d="m15 6-6 6 6 6" stroke-linecap="round" /></svg>
              <div style="width:36px;height:36px;border-radius:50%;background:#b5446e;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">MC</div>
              <div style="flex:1;"><div style="font-weight:700;font-size:14px;color:#e9edef;">Mariana Costa</div><div style="font-size:10.5px;color:#25D366;">online</div></div>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="1.8"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" /></svg>
            </div>
            <div style="flex:1;overflow-y:auto;padding:16px 13px;display:flex;flex-direction:column;gap:7px;">
              <div style="align-self:flex-start;max-width:80%;background:#202c33;padding:8px 11px;border-radius:9px 9px 9px 2px;"><div style="font-size:13px;line-height:1.4;">Oi! Vi o anúncio sobre o sistema 👋</div><div style="font-size:9.5px;color:#8696a0;text-align:right;margin-top:2px;">09:12</div></div>
              <div style="align-self:flex-end;max-width:80%;background:#005c4b;padding:8px 11px;border-radius:9px 9px 2px 9px;"><div style="font-size:13px;line-height:1.4;">Olá, Mariana! 😊 Posso te explicar como funciona?</div><div style="display:flex;align-items:center;justify-content:flex-end;gap:3px;margin-top:2px;"><span style="font-size:9.5px;color:#a7d4c5;">09:14</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#53bdeb" stroke-width="2.4"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg></div></div>
              <div style="align-self:flex-end;max-width:80%;background:#005c4b;padding:6px;border-radius:9px;"><div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.18);border-radius:7px;padding:9px 11px;"><div style="width:30px;height:36px;background:#ff5d5d;border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="font-size:7.5px;font-weight:800;color:#fff;">PDF</span></div><div style="flex:1;min-width:0;"><div style="font-size:12px;font-weight:600;">Proposta_Pro.pdf</div><div style="font-size:9.5px;color:#a7d4c5;">248 KB</div></div></div></div>
              <div style="align-self:flex-start;max-width:80%;background:#202c33;padding:8px 11px;border-radius:9px;"><div style="font-size:13px;line-height:1.4;">Pode enviar a proposta 🙌</div><div style="font-size:9.5px;color:#8696a0;text-align:right;margin-top:2px;">09:22</div></div>
            </div>
            <div style="padding:9px 11px 14px;display:flex;align-items:center;gap:8px;">
              <div style="flex:1;background:#202c33;border-radius:20px;padding:9px 14px;font-size:12.5px;color:#667781;">Mensagem</div>
              <div style="width:42px;height:42px;border-radius:50%;background:#25D366;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="18" height="18" viewBox="0 0 24 24" fill="#062014"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3Z" /></svg></div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>
