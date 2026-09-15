<template>

<div class="kat-page">

<div class="kat-inner">



 <!-- head flat (= /ekskul /events /kalender /admin/users /verify) -->

 <div class="kat-head">

 <div class="kat-head-l">

 <h1 class="kat-title" :title="total + ' sertifikat'">Sertifikat - Verifiable</h1>

 </div>

 </div>



 <!-- stats strip -->

 <div class="kat-stats" aria-label="Statistik sertifikat">

 <div class="kat-stat" :title="total + ' total'">

 <div class="kat-stat-label mono">TOTAL</div>

 <div class="kat-stat-value">{{ total }}</div>

 </div>

  <div class="kat-stat" :title="rows.filter(r=>r.tipe==='event').length+' event'">

 <div class="kat-stat-label mono">EVENT</div>

 <div class="kat-stat-value">{{ rows.filter(r=>r.tipe==='event').length }}</div>

 </div>

 <div class="kat-stat">

 <div class="kat-stat-label mono">TTD</div>

 <div class="kat-stat-value" style="font-size:15px">{{ hasTtd ? 'Terpasang' : 'QR only' }}</div>

 </div>

 </div>



 <!-- TTD KEPSEK -->

 <div class="kat-card cert-card">

 <div class="cert-card-head">

 <div>

 <div class="mono cert-kicker">TTD KEPALA SEKOLAH</div>

 <div class="cert-card-title">TTD Scan + QR verify</div>

 </div>

 <span class="mono cert-badge" :class="hasTtd?'ok':''">{{ hasTtd ? 'v Terpasang' : '- Belum ada' }}</span>

 </div>

 <div class="cert-ttd-grid">

 <div class="cert-ttd-left">

 <label class="lbl mono">UPLOAD TTD SCAN (ADMIN ONLY)</label>

 <input ref="ttdInput" type="file" accept="image/png,image/jpeg,image/webp" style="display:none" @change="onTtdFile" />

 <div style="display:flex;gap:8px;flex-wrap:wrap">

 <button class="kat-cta" :disabled="ttdLoading" @click="openTtdPicker">

 <span v-if="ttdLoading" class="spin spin-white"></span>

 {{ ttdLoading? 'Memproses...' : (hasTtd ? 'Ganti TTD Scan' : 'Upload TTD Scan') }}

 </button>

 <button v-if="hasTtd" class="kat-link" :disabled="ttdLoading" @click="deleteTtd">Hapus TTD</button>

 </div>

 <div v-if="ttdMsg" class="alert mono" :class="ttdOk?'alert-ok':'alert-err'">{{ ttdMsg }}</div>

 </div>

 <div class="cert-preview">

 <img v-if="ttdPreview" :src="ttdPreview" alt="TTD Kepsek" class="cert-preview-img" />

 <span v-else class="mono muted" style="font-size:11px;text-align:center;line-height:1.4">Preview TTD<br>(kosong = hanya QR/barcode)</span>

 </div>

 </div>

 </div>



 <!-- GENERATE -->

 <div class="kat-card cert-card">

 <div class="cert-card-head">

 <div>

 <div class="mono cert-kicker">CETAK SERTIFIKAT</div>

 <div class="cert-card-title">Generate verifiable</div>

 </div>

 <span v-if="lastCert" class="mono cert-badge ok">{{ lastCert.nomor }}</span>

 </div>

 <div class="gen-grid">

 <div class="field">

  <label class="lbl mono">TIPE</label>

  <input value="Event (1x hadir)" readonly class="kat-input" style="background:#f8fafb;color:#6B7C85" />

 </div>

 <div class="field">

  <label class="lbl mono">TARGET EVENT ID</label>

  <div class="combo">

  <input v-model="genTargetQ" @focus="showTarget=true" @input="onTargetInput" placeholder="Cari event..." class="kat-input" />

 <ul v-if="showTarget" class="combo-list">

 <li v-for="t in filteredTargets" :key="t.id" class="combo-opt" @click="pickTarget(t)">{{ t.nama }} <span class="mono muted" style="font-size:11px">#{{t.id}}</span></li>

 <li v-if="!filteredTargets.length" class="combo-opt muted">Tidak ada</li>

 </ul>

 </div>

 <div v-if="genTargetId" class="mono muted" style="font-size:11px;margin-top:4px">Pilih: #{{genTargetId}} - {{genTargetNama}}</div>

 </div>

 <div class="field">

 <label class="lbl mono">USER (SISWA)</label>

 <div class="combo">

  <input v-model="genUserQ" @focus="onUserFocus" @input="onUserInput" placeholder="Cari siswa..." class="kat-input" />

   <ul v-if="showUser" class="combo-list">

   <li v-for="u in filteredUsers" :key="u.id" class="combo-opt user-opt" :class="{'opt-disabled': genTargetId && !u.hadir}" :title="genTargetId && !u.hadir ? 'Belum hadir — tidak bisa generate' : ''" @click="pickUser(u)"><span class="u-main">{{u.nama}} <span class="mono muted" style="font-size:11px">{{u.email}} - {{u.kelas||'-'}}</span></span><span v-if="genTargetId" class="u-badges"><span class="pill mono" :class="u.hadir?'hadir-ok':'hadir-no'">{{ u.hadir ? 'Hadir' : 'Tidak hadir' }}</span><span v-if="u.total_sesi>0" class="mono muted" style="font-size:10.5px">QR {{u.sesi_hadir}}/{{u.total_sesi}}</span><span v-if="u.has_cert" class="pill mono sudah" :title="u.existing_nomor||''">Sudah terbit</span></span></li>

   <li v-if="!filteredUsers.length" class="combo-opt muted">{{ userEmptyMsg }}</li>

  </ul>

 </div>

 <div v-if="genUserId" class="mono muted" style="font-size:11px;margin-top:4px">Pilih: #{{genUserId}} - {{genUserNama}}</div>

 </div>

   <div class="field">
  <label class="lbl mono">LABEL SERTIFIKAT</label>
  <select v-model="genLabel" class="kat-input">
  <option value="PESERTA">PESERTA</option>
  <option value="PANITIA">PANITIA</option>
  <option value="JUARA 1">JUARA 1</option>
  <option value="JUARA 2">JUARA 2</option>
  <option value="JUARA 3">JUARA 3</option>
  <option value="__custom">Custom...</option>
  </select>
  <input v-if="genLabel==='__custom'" v-model="genLabelCustom" placeholder="Ketik label custom (max 50)" maxlength="50" class="kat-input" style="margin-top:8px" />
  <div class="mono muted" style="font-size:11px;margin-top:4px">Preview label: <b style="color:#b8860b">{{ genLabelFinal }}</b></div>
  </div>
  </div>
  <!-- LIVE PREVIEW pixel-perfect 297x210 — Opsi C editor -->
  <div class="cert-live-preview">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:12px 0 8px;flex-wrap:wrap">
    <div class="mono" style="font-size:10px;font-weight:700;letter-spacing:.08em;color:#6B7C85">LIVE PREVIEW</div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <button class="kat-mini" :class="editMode?'strong':''" @click="editMode=!editMode; if(editMode && !selectedField) selectedField='nama'" :title="editMode?'Keluar mode edit':'Masuk mode edit interaktif'">{{ editMode ? '✎ Mode Edit: ON' : '✎ Edit Layout' }}</button>
      <button v-if="editMode" class="kat-mini neutral" @click="resetAllLayout">Reset semua</button>
      <button v-if="editMode" class="kat-mini strong" :disabled="certLayoutSaving || !hasUnsaved" @click="saveCertLayout"><span v-if="certLayoutSaving" class="spin spin-white" style="width:12px;height:12px"></span>{{ certLayoutSaving?'Menyimpan...': hasUnsaved?'* Simpan Layout':'Tersimpan' }}</button>
    </div>
  </div>
   <div v-if="editMode" class="mono" style="font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;margin-bottom:8px">Drag teks untuk geser · tarik handle sudut untuk memperbesar · klik field untuk pilih · atur angka presisi di panel kanan · <b>Simpan Layout</b> untuk pakai di PDF selanjutnya.</div>
   <!-- FONT SELECTOR (preview only; PDF default tetap DejaVu Sans) -->
   <div class="cert-fontbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;background:#f8fafb;border:1px solid var(--m-line);border-radius:12px;padding:10px 12px">
     <div style="display:flex;gap:2px;align-items:center;color:#2F3E46;font-weight:800;font-size:11px;letter-spacing:.06em"><Type :size="14" /> FONT TEKS</div>
     <label class="fontpick"><span class="lbl mono" style="margin-bottom:2px">Nama <span :style="{color: isPdfAvailable(certFonts.nama)?'#166534':'#991b1b', fontSize:'9px', fontWeight:700}">{{ isPdfAvailable(certFonts.nama) ? '● PDF' : '⚠ Preview saja' }}</span></span>
       <select v-model="certFonts.nama" class="kat-select" style="font-size:12px;padding:7px 10px;min-height:34px"><option v-for="o in FONT_OPTIONS.nama" :key="'fn-'+o.value" :value="o.value">{{ o.label }}{{ isPdfAvailable(o.value)?' ✓':' ⚠' }}</option></select></label>
     <label class="fontpick"><span class="lbl mono" style="margin-bottom:2px">Label <span :style="{color: isPdfAvailable(certFonts.label)?'#166534':'#991b1b', fontSize:'9px', fontWeight:700}">{{ isPdfAvailable(certFonts.label) ? '● PDF' : '⚠ Preview saja' }}</span></span>
       <select v-model="certFonts.label" class="kat-select" style="font-size:12px;padding:7px 10px;min-height:34px"><option v-for="o in FONT_OPTIONS.label" :key="'fl-'+o.value" :value="o.value">{{ o.label }}{{ isPdfAvailable(o.value)?' ✓':' ⚠' }}</option></select></label>
     <label class="fontpick"><span class="lbl mono" style="margin-bottom:2px">Deskripsi <span :style="{color: isPdfAvailable(certFonts.deskripsi)?'#166534':'#991b1b', fontSize:'9px', fontWeight:700}">{{ isPdfAvailable(certFonts.deskripsi) ? '● PDF' : '⚠ Preview saja' }}</span></span>
       <select v-model="certFonts.deskripsi" class="kat-select" style="font-size:12px;padding:7px 10px;min-height:34px"><option v-for="o in FONT_OPTIONS.deskripsi" :key="'fd-'+o.value" :value="o.value">{{ o.label }}{{ isPdfAvailable(o.value)?' ✓':' ⚠' }}</option></select></label>
     <label class="fontpick"><span class="lbl mono" style="margin-bottom:2px">Nomor/Tanggal <span :style="{color: isPdfAvailable(certFonts.nomor)?'#166534':'#991b1b', fontSize:'9px', fontWeight:700}">{{ isPdfAvailable(certFonts.nomor) ? '● PDF' : '⚠ Preview saja' }}</span></span>
       <select v-model="certFonts.nomor" class="kat-select" style="font-size:12px;padding:7px 10px;min-height:34px"><option v-for="o in FONT_OPTIONS.nomor" :key="'fno-'+o.value" :value="o.value">{{ o.label }}{{ isPdfAvailable(o.value)?' ✓':' ⚠' }}</option></select></label>
     <label class="fontpick"><span class="lbl mono" style="margin-bottom:2px">TTD Nama <span :style="{color: isPdfAvailable(certFonts.ttd_nama)?'#166534':'#991b1b', fontSize:'9px', fontWeight:700}">{{ isPdfAvailable(certFonts.ttd_nama) ? '● PDF' : '⚠ Preview saja' }}</span></span>
       <select v-model="certFonts.ttd_nama" class="kat-select" style="font-size:12px;padding:7px 10px;min-height:34px"><option v-for="o in FONT_OPTIONS.ttd_nama" :key="'ft-'+o.value" :value="o.value">{{ o.label }}{{ isPdfAvailable(o.value)?' ✓':' ⚠' }}</option></select></label>
     <button class="kat-mini" :class="showIcons?'strong':''" @click="showIcons=!showIcons" :title="showIcons?'Sembunyikan icon':'Tampilkan icon'">
       <Award :size="13" style="margin-right:4px" />{{ showIcons ? 'Icon: ON' : 'Icon: OFF' }}</button>
     <span class="mono muted" style="font-size:10px" title="Award label · BadgeCheck QR · CalendarDays tanggal · Hash nomor · ShieldCheck watermark">
       <BadgeCheck :size="12" style="vertical-align:-2px" /> <CalendarDays :size="12" style="vertical-align:-2px" /> <Hash :size="12" style="vertical-align:-2px" /> <ShieldCheck :size="12" style="vertical-align:-2px" />
       preview saja — PDF fallback text</span>
   </div>
   <div v-if="!isPdfAvailable(certFonts.nama) || !isPdfAvailable(certFonts.label) || !isPdfAvailable(certFonts.deskripsi) || !isPdfAvailable(certFonts.nomor) || !isPdfAvailable(certFonts.ttd_nama)" class="mono" style="font-size:11px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:7px 10px;margin-bottom:8px">⚠ Font bertanda „Preview saja“ akan fallback ke DejaVu Sans di PDF (TTF tidak ada di <code>api/fonts/</code>). Pilih opsi bertanda ✓ untuk WYSIWYG.</div>
  <div v-if="certLayoutMsg" class="alert mono" :class="certLayoutOk?'alert-ok':'alert-err'" style="margin-bottom:8px;font-size:12px">{{ certLayoutMsg }}</div>
  <div class="cert-editor-grid" :class="{'is-edit':editMode}">
    <div ref="certStageRef" class="preview-cert-wrap" :class="{'edit-stage':editMode}" @click="editMode && !dragState && (selectedField=null)">
      <div ref="certLiveWrap" class="cert-scale-wrap">
        <div ref="certLiveInner" class="cert-scale-inner">
          <iframe ref="certPreviewFrame" :srcdoc="previewHtml" class="cert-preview-iframe cert-scaled" style="pointer-events:none;" title="Preview Sertifikat mm/pt"></iframe>
        </div>
      </div>
      <div class="preview-cert-overlay">
        <div v-for="k in FIELD_KEYS" :key="'ov-'+k" class="ov-field" :class="[{selected: selectedField===k && editMode, locked: lockedFields.has(k), dragging: dragState?.key===k}]" :style="ovStyle(k)" @pointerdown="onFieldPointerDown($event, k)" @click.stop="editMode && (selectedField=k)">
          <template v-if="editMode && selectedField===k">
            <span class="rz-handle nw" @pointerdown="onResizePointerDown($event,k,'nw')"></span>
            <span class="rz-handle ne" @pointerdown="onResizePointerDown($event,k,'ne')"></span>
            <span class="rz-handle sw" @pointerdown="onResizePointerDown($event,k,'sw')"></span>
            <span class="rz-handle se" @pointerdown="onResizePointerDown($event,k,'se')"></span>
            <span class="rz-handle n"  @pointerdown="onResizePointerDown($event,k,'n')"></span>
            <span class="rz-handle s"  @pointerdown="onResizePointerDown($event,k,'s')"></span>
            <span class="rz-handle e"  @pointerdown="onResizePointerDown($event,k,'e')"></span>
            <span class="rz-handle w"  @pointerdown="onResizePointerDown($event,k,'w')"></span>
            <span class="rz-badge mono">{{ FIELD_LABELS[k] }}</span>
          </template>
        </div>
      </div>
    </div>
    <div v-if="editMode" class="cert-props">
      <div class="mono" style="font-size:10px;font-weight:800;letter-spacing:.08em;color:#2F3E46;margin-bottom:8px">PROPERTI FIELD</div>
      <div v-if="!selectedField" class="mono muted" style="font-size:12px;padding:14px 0;text-align:center">Klik salah satu field di canvas untuk edit posisinya.</div>
      <template v-else>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px">
          <div style="font-weight:800;font-size:13px">{{ FIELD_LABELS[selectedField] }} <span class="mono muted" style="font-size:11px">({{ selectedField }})</span></div>
          <div style="display:flex;gap:6px">
            <button class="kat-mini neutral" style="padding:4px 8px;font-size:11px" @click="toggleLock(selectedField)" :title="lockedFields.has(selectedField)?'Buka kunci':'Kunci'">{{ lockedFields.has(selectedField)?'🔒':'🔓' }}</button>
            <button class="kat-mini neutral" style="padding:4px 8px;font-size:11px" @click="resetField(selectedField)">Reset</button>
          </div>
        </div>
        <div class="props-grid">
          <label class="prop"><span class="mono">X ({{ ['ttd','qr'].includes(selectedField) ? 'left %' : 'center %' }})</span><input type="number" step="0.5" :value="cssPct(selectedField,'x')" @change="onSidebarPctChange(selectedField,'x',$event.target.value)" class="kat-input" style="padding:6px 8px;font-size:12px" /></label>
          <label class="prop"><span class="mono">Y (top %)</span><input type="number" step="0.5" :value="cssPct(selectedField,'y')" @change="onSidebarPctChange(selectedField,'y',$event.target.value)" class="kat-input" style="padding:6px 8px;font-size:12px" /></label>
          <label class="prop"><span class="mono">W (%)</span><input type="number" step="0.5" :value="cssPct(selectedField,'w')" @change="onSidebarPctChange(selectedField,'w',$event.target.value)" class="kat-input" style="padding:6px 8px;font-size:12px" /></label>
          <label v-if="certLayout[selectedField]?.h!=null || selectedField==='qr'" class="prop"><span class="mono">H (%)</span><input type="number" step="0.5" :value="cssPct(selectedField,'h')" @change="onSidebarPctChange(selectedField,'h',$event.target.value)" class="kat-input" style="padding:6px 8px;font-size:12px" /></label>
          <label v-if="certLayout[selectedField]?.font_pt!=null" class="prop"><span class="mono">Font (pt)</span><input type="number" step="1" min="4" max="80" :value="certLayout[selectedField].font_pt" @change="onSidebarFontChange(selectedField,$event.target.value)" class="kat-input" style="padding:6px 8px;font-size:12px" /></label>
        </div>
        <label v-if="optionsForSelectedField().length" class="prop" style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
          <span class="mono">Jenis Font</span>
          <select class="kat-input" style="padding:6px 8px;font-size:12px" :value="currentFontForField(selectedField)" @change="onFontChangeForSelected">
            <option v-for="o in optionsForSelectedField()" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <span class="mono muted" style="font-size:10px;margin-top:2px">Berlaku untuk {{ FIELD_LABELS[selectedField] }} — sinkron preview & PDF.</span>
        </label>
        <div class="mono muted" style="font-size:10px;margin-top:8px;line-height:1.5">X = center-X untuk nama/label/deskripsi/nomor; left-edge untuk TTD & QR. Simpan untuk sinkron ke PDF (mm).</div>
        <div style="margin-top:10px;display:flex;gap:8px">
          <button class="kat-cta" style="flex:1;justify-content:center;padding:8px" :disabled="certLayoutSaving" @click="saveCertLayout">{{ certLayoutSaving?'Menyimpan...':'Simpan Layout' }}</button>
        </div>
      </template>
      <div style="margin-top:14px;border-top:1px solid var(--m-line);padding-top:10px">
        <div class="mono" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#6B7C85;margin-bottom:6px">SEMUA FIELD</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <button v-for="k in FIELD_KEYS" :key="'chip-'+k" class="kat-mini" :class="selectedField===k?'strong':''" style="font-size:11px;padding:4px 8px" @click="selectedField=k">{{ FIELD_LABELS[k] }}</button>
        </div>
        <div style="display:flex;gap:6px;margin-top:10px">
          <button class="kat-link" style="flex:1;justify-content:center;font-size:11px" @click="resetAllLayout">Reset semua ke default</button>
          <button class="kat-mini strong" style="flex:1;justify-content:center" :disabled="certLayoutSaving || !hasUnsaved" @click="saveCertLayout">Simpan</button>
        </div>
      </div>
    </div>
  </div>

   </div>

  <div v-if="genTargetId" style="margin:10px 0;display:flex;gap:8px;flex-wrap:wrap">
    <router-link :to="`/admin/sertifikat/sesi/event/${genTargetId}`" class="kat-cta" style="text-decoration:none">
      🖨️ Buka Sesi Cetak Batch
    </router-link>
  </div>

  <div class="gen-actions">

  <button class="kat-cta" :disabled="!canGen || generating || tplLoading || tplSaving || needSave" :title="saveHint || genBlockReason" @click="doGenerate">

  <span v-if="generating" class="spin spin-white"></span>

  {{ generating ? 'Memproses...' : 'Cetak Sertifikat' }}

  </button>
  <button v-if="genTargetId" class="kat-mini" :class="needSave?'strong':''" :disabled="tplLoading || tplSaving" @click="saveEventTemplate" :title="saveHint || 'Template event tersimpan'">{{ tplSaving ? 'Menyimpan...' : (needSave ? '* Simpan Template Event' : 'Template Tersimpan ✓') }}</button>
  <span v-if="genBlockReason" class="mono muted" style="font-size:11px">{{ genBlockReason }}</span>
  <span v-if="saveHint" class="mono" style="font-size:11px;color:#92400e">Wajib Simpan Template Event dulu sebelum Cetak</span>

  </div>

 <div v-if="genMsg" class="alert mono" :class="genOk?'alert-ok':'alert-err'">{{genMsg}}</div>

 <div v-if="lastCert" class="last-cert">

 <span class="mono" style="font-weight:700">{{lastCert.nomor}}</span> <span class="mono muted" style="font-size:11px"> - hash {{lastCert.hash.slice(0,12)}}...</span>

 <span class="last-cert-actions">

 <button class="kat-mini strong" @click="previewPdf(lastCert.id)">' Preview</button>

 <button class="kat-mini neutral" @click="openPdf(lastCert.id)">Download PDF</button>

 <button class="kat-mini neutral" @click="copyVerify(lastCert.hash)">Copy Link Verify</button>

  <router-link :to="'/verify/'+lastCert.hash" class="kat-mini neutral">Lihat Verifikasi</router-link>

 </span>

 </div>

 </div>



 <!-- CERT BG ADMIN (placeholder + manual note jika endpoint belum ada) -->
  <div class="kat-card cert-card">
  <div class="cert-card-head">
  <div>
  <div class="mono cert-kicker">BACKGROUND SERTIFIKAT</div>
  <div class="cert-card-title">Upload cert_bg.png</div>
  </div>
  <span class="mono cert-badge">297x210 mm</span>
  </div>
  <div class="cert-bg-grid">
  <div class="cert-bg-preview"><img :src="bgSrc" alt="BG Preview" @error="onBgImgError" v-if="!bgLoadError" style="width:100%;height:100%;object-fit:cover;border-radius:10px" /><div v-else class="mono muted" style="font-size:11px;padding:20px;text-align:center">Gagal load bg -- pastikan file ada di api/uploads/cert_bg/cert_bg.png<br><button class="kat-mini" style="margin-top:8px" @click="retryBg">Coba lagi</button></div></div>
  <div class="cert-bg-actions">
  <label class="lbl mono">UPLOAD BACKGROUND BARU (PNG 2970x2100px dianjurkan)</label>
  <input ref="bgInput" type="file" accept="image/png,image/jpeg" style="display:none" @change="onBgFile" />
  <button class="kat-cta" :disabled="bgLoading" @click="openBgPicker"><span v-if="bgLoading" class="spin spin-white"></span> {{ bgLoading ? 'Mengupload...' : 'Pilih & Upload PNG' }}</button>
  <div v-if="bgMsg" class="alert mono" :class="bgOk?'alert-ok':'alert-err'" style="margin-top:8px">{{ bgMsg }}</div>
  </div>
  </div>
  </div>

  <!-- LIST -->

 <div class="kat-card kat-table-card">

 <div class="table-head">
 <div class="table-head-l">
 <div class="table-title">Daftar Sertifikat</div>
 <div class="table-sub mono">{{ total }} sertifikat · {{ rows.length }} tampil</div>
 </div>
  <div class="table-filters">
   <button v-if="selectedIds.size" class="kat-mini strong" style="background:#991b1b;border-color:#991b1b" :disabled="bulkDeleting" @click="doBulkDelete">{{ bulkDeleting ? 'Menghapus...' : `Hapus terpilih (${selectedIds.size})` }}</button>
   <select v-model="filterEventId" @change="onEventFilterChange" class="kat-select table-filter-select">
 <option value="">Semua event</option>
 <option v-for="ev in eventList" :key="'f-ev-'+ev.id" :value="String(ev.id)">{{ ev.nama }} - #{{ ev.id }}</option>
 </select>
 <div class="kat-search small table-filter-search">
 <span class="kat-search-icon" aria-hidden="true"></span>
 <input v-model="q" @input="debounceFetchList" placeholder="Cari nama/nomor/hash..." class="kat-input" />
 <button v-if="q" class="kat-clear" @click="q=''; fetchList()" aria-label="Hapus">x</button>
 </div>
  <button v-if="filterEventId || q" class="kat-link table-filter-reset" @click="resetFilters">Reset</button>
 </div>
 </div>

 <div v-if="loading" class="skeleton-wrap"><div v-for="i in 4" :key="i" class="skel"></div></div>

 <div v-else-if="!rows.length" class="empty">Belum ada sertifikat</div>

 <div v-else class="table-wrap">

 <table>

  <thead><tr><th style="width:28px"><input v-if="rows.length" type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)" title="Pilih semua" /></th><th>NOMOR</th><th>NAMA</th><th>KELAS</th><th>LABEL</th><th>TIPE</th><th>TARGET</th><th>HASH</th><th>DITERBITKAN</th><th style="text-align:right;white-space:nowrap;min-width:230px">AKSI</th></tr></thead>

 <tbody>

   <tr v-for="r in rows" :key="r.id">

   <td><input type="checkbox" :checked="selectedIds.has(r.id)" @change="toggleOne(r.id, $event.target.checked)" /></td>
   <td class="mono" style="font-size:11px;font-weight:700">{{r.nomor}}</td>

  <td><div style="font-weight:600;font-size:13px">{{r.user_nama}}</div><div class="mono muted" style="font-size:11px">{{r.email}}</div></td>

  <td class="mono" style="font-size:11px">{{ r.kelas_snapshot || r.kelas || r.live_kelas || '-' }}</td>

  <td><span class="pill mono" style="background:#fffbeb;border-color:#fde68a;color:#92400e;font-size:11px">{{ r.label || 'PESERTA' }}</span></td>

   <td><span class="pill mono tag-pembina">{{r.tipe}}</span></td>

  <td class="mono" style="font-size:11px">{{r.target_nama||'#'+r.target_id}}</td>

  <td class="mono" style="font-size:10.5px">{{r.hash.slice(0,10)}}...<button class="kat-mini neutral" @click="copyVerify(r.hash)" style="margin-left:6px;padding:2px 8px;font-size:11px">copy</button></td>

  <td class="mono" style="font-size:11px">{{r.issued_at}}</td>

 <td style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center;justify-content:flex-end;white-space:nowrap;min-width:230px">

 <button class="kat-mini strong" @click="previewPdf(r.id)">Preview</button>

  <button class="kat-mini neutral" @click="openPdf(r.id)">PDF</button>

   <router-link :to="'/verify/'+r.hash" class="kat-mini neutral">Verify</router-link>

  <button class="kat-mini neutral" style="color:#991b1b;border-color:#fecaca" @click="doDelete(r)">Hapus</button>

  </td>

 </tr>

 </tbody>

 </table>

 </div>

  <div class="kat-paging inside" v-if="total">

 <span class="kat-count mono">{{ (page-1)*limit+1 }}- {{ Math.min(page*limit,total) }} dari {{ total }}</span>

 <div class="kat-paging-btns">

 <button class="kat-page-btn" :disabled="page<=1" @click="page=Math.max(1,page-1); loadSertifikat()"><span class="kat-page-num mono">{{page}} / {{Math.ceil(total/limit)}}</span></button>

 <button class="kat-page-btn" :disabled="page>=Math.ceil(total/limit)" @click="page++;fetchList()">Next</button>

 </div>

 </div>

 </div>



 <div v-if="toast" class="kat-toast mono" :class="toastOk?'ok':'err'">{{toast}}</div>



 <!-- PREVIEW MODAL -->

 <Teleport to="body">

 <div v-if="showPreview" class="preview-overlay" @click.self="closePreview">

 <div class="preview-modal">

 <div class="preview-head">

 <div>

 <div class="mono" style="font-size:10px;font-weight:700;letter-spacing:.08em;color:#6B7C85">PREVIEW SERTIFIKAT</div>

 <div style="font-size:13px;font-weight:800">{{ previewNomor||'-' }}</div>

 </div>

 <button class="kat-link" @click="closePreview">x Tutup</button>

 </div>

  <div class="preview-tabs" style="display:flex;gap:6px;margin-bottom:10px">
  <button class="kat-mini" :class="previewTab==='html'?'strong':''" @click="previewTab='html'">Pratinjau HTML (pixel-perfect)</button>
  <button class="kat-mini" :class="previewTab==='pdf'?'strong':''" @click="previewTab='pdf'">PDF (iframe)</button>
  </div>
   <div v-if="previewTab==='html'" ref="certModalWrap" class="cert-scale-wrap cert-scale-modal" style="margin-bottom:10px;">
    <div ref="certModalInner" class="cert-scale-inner">
     <iframe v-if="previewTab==='html'" :srcdoc="previewModalHtml" class="cert-preview-iframe cert-scaled" style="pointer-events:auto;" title="Preview HTML mm/pt"></iframe>
    </div>
   </div>
  <div v-if="previewLoading" style="display:grid;place-items:center;padding:40px"><span class="spin"></span><span class="mono muted" style="font-size:12px;margin-left:8px">Memuat PDF...</span></div>

  <iframe v-else-if="previewUrl && previewTab==='pdf'" :src="pdfPreviewSrc" class="pdf-frame" style="width:100%;height:min(68vh,640px);min-height:320px;border:none;background:#fff;border-radius:12px;display:block"></iframe>

  <div v-else-if="previewTab==='pdf'" class="mono muted" style="padding:16px;text-align:center;font-size:12px">Pilih tab PDF untuk memuat</div>
  <div v-else-if="!previewUrl" class="mono muted" style="padding:12px;text-align:center;font-size:11px">HTML preview di atas identik koordinat PDF; klik tab PDF untuk load iframe</div>

 <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">

 <button v-if="previewUrl" class="kat-cta" @click="downloadFromPreview">Download PDF</button>

 <button class="kat-link" @click="closePreview">Tutup</button>

 </div>

 </div>

 </div>

 </Teleport>



</div>

</div>

</template>

<script setup>

import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'

import { api, getCsrf } from '../lib/api.js'
import { Award, BadgeCheck, CalendarDays, Hash, ShieldCheck, Type } from 'lucide-vue-next'

// === SELECTABLE FONTS — preview only. PDF default tetap DejaVu Sans (aman Dompdf). ===
// Semua 13 family TTF tersedia untuk SEMUA field (fix: sebelumnya per-field dibatasi, kini WYSIWYG penuh).
// 13 = Cormorant Garamond, Playfair Display, Cinzel, DM Serif Display, Inter, DM Sans, Jost, Source Serif 4, JetBrains Mono, IBM Plex Mono, Great Vibes, Allura, Alex Brush
const ALL_FONTS = [
  { value: 'DejaVu Sans', label: 'DejaVu Sans (default PDF)' },
  { value: 'Great Vibes', label: 'Great Vibes 400 ★ script' },
  { value: 'Allura', label: 'Allura 400 ★ script' },
  { value: 'Alex Brush', label: 'Alex Brush 400 ★ script' },
  { value: 'Cormorant Garamond', label: 'Cormorant Garamond 700' },
  { value: 'Playfair Display', label: 'Playfair Display 700/800' },
  { value: 'Cinzel', label: 'Cinzel 700' },
  { value: 'DM Serif Display', label: 'DM Serif Display 400' },
  { value: 'Inter', label: 'Inter 400/500/700' },
  { value: 'DM Sans', label: 'DM Sans 400/700' },
  { value: 'Jost', label: 'Jost 500/600' },
  { value: 'Source Serif 4', label: 'Source Serif 4 400' },
  { value: 'JetBrains Mono', label: 'JetBrains Mono 400 mono' },
  { value: 'IBM Plex Mono', label: 'IBM Plex Mono 400 mono' },
]
const FONT_OPTIONS = {
  nama: [...ALL_FONTS],
  label: [...ALL_FONTS],
  deskripsi: [...ALL_FONTS],
  nomor: [...ALL_FONTS],
  ttd_nama: [...ALL_FONTS],
}
// Google Fonts css2 family slug per value (untuk <link> preview)
const FONT_GF_SLUG = {
  'Cormorant Garamond': 'Cormorant+Garamond:wght@600;700',
  'Playfair Display': 'Playfair+Display:wght@700;800',
  'Cinzel': 'Cinzel:wght@600;700',
  'DM Serif Display': 'DM+Serif+Display:ital,wght@0,400;1,400',
  'Inter': 'Inter:wght@400;500;600;700;800',
  'DM Sans': 'DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700',
  'Jost': 'Jost:wght@500;600',
  'Source Serif 4': 'Source+Serif+4:opsz,wght@8..60,400;8..60,600',
  'JetBrains Mono': 'JetBrains+Mono:wght@400;500',
  'IBM Plex Mono': 'IBM+Plex+Mono:wght@400;500',
  'Great Vibes': 'Great+Vibes',
  'Allura': 'Allura',
  'Alex Brush': 'Alex+Brush',
}
const PDF_AVAILABLE = new Set(['DejaVu Sans','Arial','Georgia','Times New Roman','Cormorant Garamond','Inter','Source Serif 4','JetBrains Mono','Great Vibes','Alex Brush','Playfair Display','Cinzel','DM Serif Display','DM Sans','Jost','IBM Plex Mono','Allura'])
function isPdfAvailable(fam){ return PDF_AVAILABLE.has(String(fam||'')) }
const DEFAULT_FONTS = { nama: 'Great Vibes', label: 'DejaVu Sans', deskripsi: 'DejaVu Sans', nomor: 'DejaVu Sans', ttd_nama: 'DejaVu Sans' }
function loadCertFonts(){
  try{
    const raw = localStorage.getItem('certFonts')
    if(raw){ const p = JSON.parse(raw); return { ...DEFAULT_FONTS, ...p } }
  }catch{}
  return { ...DEFAULT_FONTS }
}
const certFonts = ref(loadCertFonts())
// Samakan dengan SesiCetak: terapkan font server (fonts_parsed) ke certFonts.
// Hanya key non-kosong & lolos whitelist (DejaVu + embedded TTF); selain itu abaikan.
function applyServerFonts(srv){
  if(!srv || typeof srv !== 'object') return false
  const allow = new Set(['DejaVu Sans', 'Arial', 'Georgia', 'Times New Roman', ...Object.keys(FONT_GF_SLUG)])
  let hit = false
  const next = { ...(certFonts.value || DEFAULT_FONTS) }
  for(const k of Object.keys(DEFAULT_FONTS)){
    const v = typeof srv[k] === 'string' ? srv[k].trim() : ''
    if(v && allow.has(v)){ next[k] = v; hit = true }
  }
  if(hit) certFonts.value = next
  return hit
}
const showIcons = ref(false)
try{ showIcons.value = localStorage.getItem('certShowIcons') === '1' }catch{}
watch(certFonts, (v)=>{ try{ localStorage.setItem('certFonts', JSON.stringify(v)) }catch{} }, { deep: true })
watch(showIcons, (v)=>{ try{ localStorage.setItem('certShowIcons', v ? '1' : '0') }catch{} })
// <link> Google Fonts sesuai pilihan aktif (preview iframe srcdoc saja)
const certFontsLink = computed(()=>{
  const vals = [...new Set(Object.values(certFonts.value || {}))].filter(v => v && v !== 'DejaVu Sans' && FONT_GF_SLUG[v])
  if(!vals.length) return ''
  const fams = vals.map(v => 'family=' + FONT_GF_SLUG[v]).join('&')
  return `<link href="https://fonts.googleapis.com/css2?${fams}&display=swap" rel="stylesheet">`
})
function certFontCss(v){
  const fam = String(v || 'DejaVu Sans').replace(/'/g, '')
  if(fam === 'DejaVu Sans') return 'DejaVu Sans, Arial, sans-serif'
  return `'${fam}', 'DejaVu Sans', Arial, sans-serif`
}

// event-only: filterTipe/filterEkskulId dipertahankan sebagai state tak terpakai agar resetFilters kompatibel
const rows=ref([]), total=ref(0), page=ref(1), limit=20, q=ref(''), filterTipe=ref('event'), filterEkskulId=ref(''), filterEventId=ref(''), loading=ref(false)

const ttdInput=ref(null), ttdPreview=ref(''), hasTtd=ref(false), ttdLoading=ref(false), ttdMsg=ref(''), ttdOk=ref(true)

// TTD via /api/ttd (bytes + ETag) — /settings.ttd_kepsek deprecated (null).
// Pakai helper api() (BASE + credentials) agar auth sama seperti request /settings.
// Kembalikan data-URI agar konsumen lama (preview card, renderCertHtml gate data:image/) tetap jalan.
// 404 (belum dipasang) -> throw oleh api() -> caller fallback '' (img disembunyikan, has_ttd=false).
async function ttdDataUri(){
  const res=await api('/ttd')
  const blob=await res.blob()
  return await new Promise((ok,bad)=>{ const fr=new FileReader(); fr.onload=()=>ok(String(fr.result||'')); fr.onerror=bad; fr.readAsDataURL(blob) })
}

async function fetchTtdSettings(){

  try{

  const j=await api('/settings')

  hasTtd.value=!!j.data?.has_ttd

  if(j.data?.has_ttd){ try{ ttdPreview.value=await ttdDataUri() }catch{ ttdPreview.value=''; hasTtd.value=false } }
  else ttdPreview.value=''

 try{ kepsekNama.value=j.data?.kepsek_nama||kepsekNama.value; kepsekNip.value=j.data?.kepsek_nip||kepsekNip.value }catch{}

  }catch{}

 }

async function onTtdFile(e){

 const f=e.target?.files?.[0]; if(!f) return

 if(f.size>2*1024*1024){ ttdOk.value=false; ttdMsg.value='Maks 2MB'; setTimeout(()=>ttdMsg.value='',2500); e.target.value=''; return }

 if(!['image/png','image/jpeg','image/jpg','image/webp'].includes(f.type)){ ttdOk.value=false; ttdMsg.value='Hanya PNG/JPG/WEBP'; setTimeout(()=>ttdMsg.value='',2500); e.target.value=''; return }

 ttdLoading.value=true; ttdMsg.value=''

 try{

 const fd=new FormData(); fd.append('ttd', f)

 const csrf=await getCsrf()

 const res=await fetch('/api/settings/ttd',{method:'POST', credentials:'include', headers:{'X-CSRF-Token': csrf||''}, body: fd})

 const j=await res.json().catch(()=>({success:false, error:{message:res.statusText}}))

 if(!res.ok || !j.success) throw j

  hasTtd.value=true; try{ ttdPreview.value=await ttdDataUri() }catch{ ttdPreview.value='' }; ttdOk.value=true; ttdMsg.value='TTD berhasil dipasang'

 setTimeout(()=>ttdMsg.value='',2000)

 }catch(err){

 ttdOk.value=false; ttdMsg.value=err?.error?.message||err?.message||'Gagal upload TTD'

 setTimeout(()=>ttdMsg.value='',3000)

 }finally{ ttdLoading.value=false; if(e.target) e.target.value='' }

}

function openTtdPicker(){ try{ ttdInput.value && ttdInput.value.click() }catch{} }

async function deleteTtd(){

 ttdLoading.value=true; ttdMsg.value=''

 try{

 const j=await api('/settings/ttd',{method:'DELETE'})

 hasTtd.value=false; ttdPreview.value=''; ttdOk.value=true; ttdMsg.value='TTD dihapus - PDF akan pakai QR/barcode + NIP'

 setTimeout(()=>ttdMsg.value='',2000)

 }catch(err){

 ttdOk.value=false; ttdMsg.value=err?.error?.message||'Gagal hapus'

 setTimeout(()=>ttdMsg.value='',3000)

 }finally{ ttdLoading.value=false }

}

// event-only: genTipe fixed 'event'
const genTipe=ref('event'), genTargetId=ref(null), genTargetNama=ref(''), genTargetQ=ref('')
const genLabel=ref('PESERTA'), genLabelCustom=ref('')
const genLabelOptions=['PESERTA','PANITIA','JUARA 1','JUARA 2','JUARA 3']
const genLabelFinal=computed(()=> genLabel.value==='__custom' ? (genLabelCustom.value.trim() || 'PESERTA') : genLabel.value)
const todayLabel=computed(()=> new Date().toLocaleDateString('id-ID',{day:'2-digit',month:'long',year:'numeric'}))
const kepsekNama=ref('Kepala Sekolah'), kepsekNip=ref('NIP.')
const bgInput=ref(null), bgLoading=ref(false), bgMsg=ref(''), bgOk=ref(true), bgLoadError=ref(false)
const previewTab=ref('html')
const bgSrc=ref('/api/uploads/cert_bg/cert_bg.png')
const bgMeta=ref({w:null,h:null,aspect:null,mime:'',size:null})
const bgAspectHint=computed(()=>{
  const a=bgMeta.value.aspect
  if(!a) return ''
  const ideal=297/210
  const diff=Math.abs(a-ideal)
  if(diff>0.03) return `Aspect ${a.toFixed(2)}:1 tidak exact A4 (1.41:1) — akan di-crop center (cover) agar tetap pixel-perfect`
  return `Aspect ${a.toFixed(2)}:1 — mendekati A4 (1.41:1), pas`
})

// === CERT LAYOUT EDITOR — Opsi C: drag+resize native + sidebar properti ===
const SECOND_FONTS = computed(() => !!(FONT_OPTIONS && FONT_OPTIONS.nama && FONT_OPTIONS.nama.length))
const FIELD_KEYS = ['nama','label','deskripsi','ttd','qr','nomor']
const FONT_FIELD_KEYS = { nama: 'nama', label: 'label', deskripsi: 'deskripsi', nomor: 'nomor', ttd: 'ttd_nama', qr: 'nomor' }
function fontFieldForKey(k){ return FONT_FIELD_KEYS[k] || null }
function optionsForSelectedField(){
  if (!selectedField.value) return []
  const fk = fontFieldForKey(selectedField.value)
  if (!fk) return []
  const opts = FONT_OPTIONS && FONT_OPTIONS[fk]
  return Array.isArray(opts) ? opts : []
}
function currentFontForField(k){
  const fk = fontFieldForKey(k)
  if (!fk) return ''
  return String((certFonts.value || {})[fk] || '')
}
function onFontChangeForSelected(e){
  const fk = fontFieldForKey(selectedField.value)
  if (!fk) return
  const v = String(e.target?.value || '')
  certFonts.value = { ...(certFonts.value || {}), [fk]: v }
}
const FIELD_LABELS = { nama:'Nama Peserta', label:'Label', deskripsi:'Deskripsi', ttd:'Tanda Tangan', qr:'QR Code', nomor:'Nomor Sertifikat' }
const DEFAULT_LAYOUT = {
  nama: { x:148.5, y:88, w:180, font_pt:28 },
  label: { x:148.5, y:135, w:180, font_pt:18 },
  deskripsi:{ x:148.5, y:150, w:200, font_pt:11 },
  ttd: { x:55, y:175, w:60, font_pt:10 },
  qr:  { x:242, y:175, w:30, h:30 },
  nomor:{ x:148.5, y:195, w:180, font_pt:7 },
}
function cloneLayout(o){ return JSON.parse(JSON.stringify(o)) }
const certLayout = ref(cloneLayout(DEFAULT_LAYOUT))
const certLayoutLoading = ref(false)
const certLayoutSaving = ref(false)
const certLayoutMsg = ref('')
const certLayoutOk = ref(true)
const selectedField = ref(null) // key or null
const editMode = ref(false)
const certStageRef = ref(null)
const dragState = ref(null) // {key,startX,startY,initX,initY}
const resizeState = ref(null) // {key,dir,startX,startY,initX,initY,initW,initH}
const lockedFields = ref(new Set())
const hasUnsaved = ref(false)
const globalCertLayoutSnap = ref(null) // snapshot global untuk sync per-event
// client-side mirror of api/cert_template.php cert_render_html — mm/pt only, no %/cqi
function escHtml(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') }
function fmtMm(n){ let s=Number(n).toFixed(2); s=s.replace(/0+$/,'').replace(/\.$/,''); return s }
function renderCertHtml(data, layout, fontsOpt, iconsOpt){
  const fonts = fontsOpt || certFonts.value || DEFAULT_FONTS
  const icons = iconsOpt !== undefined ? iconsOpt : showIcons.value
  const isNamaScript = ['Great Vibes','Allura','Alex Brush'].includes(String(fonts.nama||''))
  const namaW = isNamaScript ? '400' : '800'
  const fNama = certFontCss(fonts.nama), fLabel = certFontCss(fonts.label),
        fDesk = certFontCss(fonts.deskripsi), fNomor = certFontCss(fonts.nomor),
        fTtd = certFontCss(fonts.ttd_nama)
  const def={
    nama:     {x:148.5,y:100,w:180,font_pt:28,color:'#0E1442',font_weight:'bold'},
    label:    {x:148.5,y:135,w:180,font_pt:18,color:'#b8860b',font_weight:'bold',uppercase:true},
    deskripsi:{x:148.5,y:150,w:200,font_pt:11,color:'#444444'},
    ttd:      {x:55,y:175,w:60,font_pt:10,color:'#18181b'},
    qr:       {x:242,y:175,w:30,h:30},
    nomor:    {x:148.5,y:195,w:180,font_pt:7,color:'#666666'},
  }
  const L={}
  for(const k of Object.keys(def)){
    const v=layout?.[k]
    L[k]= v && typeof v==='object' ? {...def[k],...v} : {...def[k]}
  }
  const mm=fmtMm
  const esc=escHtml
  const centered={nama:1,label:1,deskripsi:1,nomor:1}
  const fieldPos=(key)=>{
    const v=L[key]; const x=parseFloat(v.x??148.5), y=parseFloat(v.y??100), w=parseFloat(v.w??60)
    const isCentered=!!centered[key]
    const left=isCentered?(x-w/2):x
    let out=`left:${mm(left)}mm; top:${mm(y)}mm; width:${mm(w)}mm;`
    if(v.h!=null && v.h!=='' ) out+=` height:${mm(parseFloat(v.h))}mm;`
    return [out,v]
  }
  let label=String(data.label??'PESERTA')
  if(L.label.uppercase) label=label.toUpperCase()
  const deskripsiHtml=data.deskripsi_html??''
  const ttd=data.ttd||{}
  const ttdDate=ttd.date||''
  const ttdImgUri=ttd.img_data_uri||''
  const ttdNama=ttd.nama||''
  const ttdNip=ttd.nip||''
  const qrDataUri=data.qr_data_uri||''
  const qrUrl=data.qr_url||''
  const nomor=data.nomor||'-'
  const hash=data.hash||''
  const issuedAt=data.issued_at||''
  const bgDataUri=data.bg_data_uri||''
  const bgFallback=data.bg_fallback_html||''
  const css='@page{ size:297mm 210mm landscape; margin:0; }'
    +'body{ margin:0; padding:0; width:297mm; height:210mm; position:relative; font-family:DejaVu Sans, Arial, sans-serif; color:#18181b; }'
    +'.bg{ position:absolute; left:0; top:0; width:297mm; height:210mm; z-index:0; object-fit:cover; object-position:center; }'
    +'.field{ position:absolute; z-index:1; text-align:center; overflow:visible; line-height:1.2; word-wrap:break-word; overflow-wrap:break-word; }'
    +`.field-nama{ font-weight:${namaW}; color:#0E1442; font-family:${fNama}; }`
    +`.field-label{ font-weight:800; color:#b8860b; letter-spacing:0.08em; text-transform:uppercase; font-family:${fLabel}; }`
    +`.field-deskripsi{ color:#444444; line-height:1.5; font-family:${fDesk}; }`
    +'.field-ttd{ color:#18181b; }'
    +'.field-qr{ text-align:center; }'
    +`.field-nomor{ color:#666666; font-family:${fNomor}; }`
    +`.field-ttd-name{ font-family:${fTtd}; }`
  let bgHtml
  if(bgDataUri) bgHtml=`<img class="bg" src="${esc(bgDataUri)}" alt="bg" style="position:absolute; left:0; top:0; width:297mm; height:210mm; object-fit:cover; object-position:center;">`
  else if(bgFallback) bgHtml=bgFallback
  else bgHtml=`<div class="bg" style="position:absolute; left:0; top:0; width:297mm; height:210mm; background:#f8fafc; border:3mm double #0E1442;"></div>`
  const [namaPos,namaL]=fieldPos('nama')
  const [labelPos,labelL]=fieldPos('label')
  const [descPos,descL]=fieldPos('deskripsi')
  const [ttdPos,ttdL]=fieldPos('ttd')
  const [qrPos,qrL]=fieldPos('qr')
  const [nomorPos,nomorL]=fieldPos('nomor')
  const fontPt=(v,fb)=> parseFloat(v.font_pt??fb)
  const fw=(v,defFw='normal')=>{ if(v.font_weight) return String(v.font_weight); if(v.bold) return 'bold'; return defFw }
  // inline SVG icons — preview srcdoc saja (PDF biarkan fallback text)
  const svgW = (inner)=>`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:3mm;height:3mm;vertical-align:-0.5mm;margin-right:1mm;" aria-hidden="true">${inner}</svg>`
  const SVG = {
    award: '<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    badge: '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76z"/><path d="m9 12 2 2 4-4"/>',
    hash: '<line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/>',
  }
  const namaHtml=`<div class="field field-nama" style="${namaPos} font-size:${mm(fontPt(namaL,28))}pt; font-weight:${esc(fw(namaL,namaW))}; color:${esc(namaL.color||'#0E1442')}; text-align:center; font-family:${esc(fNama)};">${esc(data.nama||'')}</div>`
  const labelHtml=`<div class="field field-label" style="${labelPos} font-size:${mm(fontPt(labelL,18))}pt; font-weight:${esc(fw(labelL,'800'))}; color:${esc(labelL.color||'#b8860b')}; text-align:center; letter-spacing:0.08em; font-family:${esc(fLabel)};">${icons?svgW(SVG.award):''}${esc(label)}</div>`
  const descHtml=`<div class="field field-deskripsi" style="${descPos} font-size:${mm(fontPt(descL,11))}pt; color:${esc(descL.color||'#444444')}; text-align:center; line-height:1.5; font-family:${esc(fDesk)};">${deskripsiHtml}</div>`
  // TTD: hanya data:image/ yang dirender (selaras backend cert_template.php:187 — URL arbitrer jadi spacer agar PDF konsisten)
  const hasTtdImg=ttdImgUri && String(ttdImgUri).startsWith('data:image/')
  let ttdImgHtml
  if(hasTtdImg) ttdImgHtml=`<img src="${esc(ttdImgUri)}" alt="TTD Kepsek" style="height:18mm; max-width:55mm; object-fit:contain; display:block; margin:2mm auto 1mm auto;">`
  else ttdImgHtml=`<div style="height:18mm;"></div>`
  const ttdInner=`<div style="font-size:7pt; color:#52525b;">${icons?svgW(SVG.cal):''}${esc(ttdDate)}</div>`+ttdImgHtml+`<div class="field-ttd-name" style="font-weight:700; border-top:0.4mm solid #18181b; padding-top:1.5mm; font-size:8pt; font-family:${esc(fTtd)};">${esc(ttdNama)}</div>`+`<div style="color:#71717a; font-size:7pt;">${esc(ttdNip?('NIP. '+ttdNip):'Kepala Sekolah')}</div>`
  const ttdHtml=`<div class="field field-ttd" style="${ttdPos} font-size:${mm(fontPt(ttdL,10))}pt; text-align:center; color:${esc(ttdL.color||'#18181b')};">${ttdInner}</div>`
  let qrImgHtml
  if(qrDataUri) qrImgHtml=`<img src="${esc(qrDataUri)}" alt="QR Verify" style="width:30mm; height:30mm; border:0.4mm solid #e4e4e7; display:block; margin:0 auto;">`
  else qrImgHtml=`<div style="width:30mm; height:30mm; border:0.4mm dashed #ccc; display:block; margin:0 auto;"></div>`
  const qrInner=qrImgHtml
  const qrHtml=`<div class="field field-qr" style="${qrPos} text-align:center;">${qrInner}</div>`
  const nomorInner=`Nomor: ${esc(nomor)} &nbsp;|&nbsp; ${icons?svgW(SVG.hash):''}Hash: ${esc(String(hash).slice(0,16))}… &nbsp;|&nbsp; Diterbitkan: ${esc(issuedAt)}`
  const nomorHtml=`<div class="field field-nomor" style="${nomorPos} font-size:${mm(fontPt(nomorL,7))}pt; color:${esc(nomorL.color||'#666666')}; text-align:center; font-family:${esc(fNomor)};">${nomorInner}</div>`
  const gfLink = certFontsLink.value
  return `<!DOCTYPE html><html><head><meta charset="utf-8">${gfLink}<style>${css}</style></head><body>${bgHtml}${namaHtml}${labelHtml}${descHtml}${ttdHtml}${qrHtml}${nomorHtml}${icons?`<div style="position:absolute;left:8mm;bottom:6mm;font-size:5pt;color:#a1a1aa;opacity:.7;">${svgW(SVG.shield)}Dokumen terverifikasi</div>`:''}</body></html>`
}
function absUrl(u){ const s=String(u||''); if(!s) return s; if(s.startsWith('data:')||s.startsWith('http')||s.startsWith('blob:')) return s; if(s.startsWith('/')) try{ return location.origin+s }catch{ return s }; return s }
const previewHtml=computed(()=>{
  const data={
    nama: genUserNama.value || 'Nama Peserta',
    label: genLabelFinal.value || 'PESERTA',
    deskripsi_html: `atas partisipasi aktif pada <b>${escHtml(genTargetNama.value || 'Event')}</b> sesuai kriteria kehadiran yang ditetapkan sekolah.`,
    ttd: { date: todayLabel.value, img_data_uri: absUrl(ttdPreview.value||''), nama: kepsekNama.value||'Kepala Sekolah', nip: (kepsekNip.value||'').replace(/^NIP\.\s*/i,'') },
    qr_data_uri: '',
    qr_url: (lastCert.value?.hash ? (location.origin+'/verify/'+lastCert.value.hash) : '/verify/<hash>'),
    nomor: lastCert.value?.nomor || '####/SERT-EVT/##/#####',
    hash: lastCert.value?.hash || '',
    issued_at: todayLabel.value,
    bg_data_uri: absUrl(bgSrc.value||''),
  }
  return renderCertHtml(data, certLayout.value)
})
const previewId=ref(null)
const previewRow=computed(()=> {
  if(previewId.value) { const f=rows.value.find(x=> String(x.id)===String(previewId.value)); if(f) return f }
  const byNomor=rows.value.find(x=> x.nomor===previewNomor.value); if(byNomor) return byNomor
  return lastCert.value || null
})
const previewModalHtml=computed(()=>{
  const r=previewRow.value
  const data={
    nama: r?.user_nama || genUserNama.value || 'Nama',
    label: r?.label || genLabelFinal.value || 'PESERTA',
    deskripsi_html: `atas partisipasi pada <b>${escHtml(r?.target_nama||genTargetNama.value||'-')}</b>`,
    ttd: { date: todayLabel.value, img_data_uri: absUrl(ttdPreview.value||''), nama: kepsekNama.value||'Kepala Sekolah', nip: (kepsekNip.value||'').replace(/^NIP\.\s*/i,'') },
    qr_data_uri: '',
    qr_url: r?.hash ? (location.origin+'/verify/'+r.hash) : '/verify/<hash>',
    nomor: r?.nomor || previewNomor.value || '-',
    hash: r?.hash || '',
    issued_at: r?.issued_at || todayLabel.value,
    bg_data_uri: absUrl(bgSrc.value||''),
  }
  return renderCertHtml(data, certLayout.value)
})
// overlay helpers (% for editor hit-areas, not for iframe content)
function ovStyle(key){
  const v=certLayout.value[key]||DEFAULT_LAYOUT[key]
  const isCentered=!['ttd','qr'].includes(key)
  const w=v.w ?? DEFAULT_LAYOUT[key].w ?? 60
  const x=v.x ?? DEFAULT_LAYOUT[key].x ?? 148.5
  const y=v.y ?? DEFAULT_LAYOUT[key].y ?? 100
  const leftPct=isCentered? ((x - w/2)/297*100) : (x/297*100)
  const topPct=y/210*100
  const wPct=w/297*100
  const hPct=v.h!=null ? (v.h/210*100) : null
  const s={ left:leftPct+'%', top:topPct+'%', width:wPct+'%' }
  if(hPct!=null) s.height=hPct+'%'
  return s
}
function cssPct(key, prop){
  const v=certLayout.value[key]||DEFAULT_LAYOUT[key]
  const isCentered=!['ttd','qr'].includes(key)
  const w=v.w ?? DEFAULT_LAYOUT[key].w ?? 60
  const x=v.x ?? DEFAULT_LAYOUT[key].x ?? 148.5
  const y=v.y ?? DEFAULT_LAYOUT[key].y ?? 100
  const leftPct=isCentered? ((x - w/2)/297*100) : (x/297*100)
  const topPct=y/210*100
  const wPct=w/297*100
  const hPct=v.h!=null ? (v.h/210*100) : null
  if(prop==='x') return isCentered ? +((leftPct + wPct/2).toFixed(2)) : +(leftPct.toFixed(2))
  if(prop==='y') return +(topPct.toFixed(2))
  if(prop==='w') return +(wPct.toFixed(2))
  if(prop==='h') return hPct!=null? +(hPct.toFixed(2)):0
  return 0
}
// load from backend
async function loadCertLayout(){
  certLayoutLoading.value=true
  try{
    const j=await api('/settings/cert_layout')
    const parsed=j.data?.parsed || j.data?.cert_layout_parsed || null
    const raw=j.data?.cert_layout || ''
    let src=null
    if(parsed && typeof parsed==='object') src=parsed
    else if(raw && typeof raw==='string' && raw.trim().startsWith('{')){ try{ src=JSON.parse(raw) }catch{} }
    else {
      // fallback: try GET /settings
      try{ const s2=await api('/settings'); const r2=s2.data?.cert_layout||''; if(r2) src=JSON.parse(r2) }catch{}
    }
    if(src && typeof src==='object'){
      const next=cloneLayout(DEFAULT_LAYOUT)
      for(const k of FIELD_KEYS){ if(src[k] && typeof src[k]==='object') next[k]={...next[k], ...src[k]} }      // backward-compat: abaikan legacy key 'kelas' bila masih ada di saved layout lama
      if('kelas' in next) delete next.kelas
      // preserve bg_* keys
      for(const bk of ['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at']) if(src[bk]!=null) next[bk]=src[bk]
      certLayout.value=next
    }
  }catch(e){ /* keep defaults */ }
  finally{ certLayoutLoading.value=false }
  // snapshot global untuk sync per-event: jika genTargetId ada dan belum custom, push layout global ke server
  try{
    globalCertLayoutSnap.value = JSON.parse(JSON.stringify(certLayout.value))
    if(genTargetId.value && tplLoadedId.value===genTargetId.value && tplFontsSnap.value && (tplLoadedId.value==null || !tplLayoutSnap.value)){
      // template event belum custom layout — biarkan preview tetap pakai global; saveEventTemplate akan pakai global snap
    }
  }catch{}
}
async function saveCertLayout(){
  certLayoutSaving.value=true; certLayoutMsg.value=''
  try{
    const payload={}
    for(const k of FIELD_KEYS) payload[k]=certLayout.value[k]
    // preserve bg_* from current if any
    for(const bk of ['bg_w','bg_h','bg_mime','bg_aspect','bg_updated_at']) if(certLayout.value[bk]!=null) payload[bk]=certLayout.value[bk]
    const csrf=await getCsrf()
    const res=await fetch('/api/settings/cert_layout',{method:'POST', credentials:'include', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf||''}, body: JSON.stringify({cert_layout: JSON.stringify(payload), fonts_json: JSON.stringify(certFonts.value || DEFAULT_FONTS)})})
    const j=await res.json().catch(()=>({success:false,error:{message:res.statusText}}))
    if(!res.ok || !j.success) throw j
    certLayoutOk.value=true; certLayoutMsg.value='Layout tersimpan ✓ — PDF selanjutnya pakai posisi baru'
    hasUnsaved.value=false
    try{ globalCertLayoutSnap.value = JSON.parse(JSON.stringify(certLayout.value)) }catch{}
    // jika event dipilih dan template belum custom, auto-sync ke template event juga agar PDF per-event langsung sync
    if(genTargetId.value && tplLoadedId.value===genTargetId.value && !tplLayoutSnap.value){
      try{ await saveEventTemplate(true); certLayoutMsg.value='Layout tersimpan ✓ — sync ke event juga' }catch{}
    }
    setTimeout(()=>certLayoutMsg.value='',3500)
  }catch(err){
    certLayoutOk.value=false; certLayoutMsg.value=err?.error?.message||err?.message||'Gagal simpan layout'
    setTimeout(()=>certLayoutMsg.value='',3000)
  }finally{ certLayoutSaving.value=false }
}
function resetField(key){
  certLayout.value[key]=cloneLayout(DEFAULT_LAYOUT)[key]
  hasUnsaved.value=true
}
function resetAllLayout(){
  certLayout.value=cloneLayout(DEFAULT_LAYOUT)
  hasUnsaved.value=true
  selectedField.value=null
}
function toggleLock(key){
  const s=new Set(lockedFields.value)
  if(s.has(key)) s.delete(key); else s.add(key)
  lockedFields.value=s
}
// sidebar numeric handlers: pct input -> mm
function onSidebarPctChange(key, prop, val){
  const num=parseFloat(val); if(isNaN(num)) return
  const cur=certLayout.value[key]||{}
  if(prop==='x'){
    // X = center-X untuk centered, left-edge untuk ttd/qr (konsisten dgn cssPct)
    const mm = clampPctInput(num, 0, 100)/100*297
    cur.x = mm
  } else if(prop==='y'){
    cur.y = clampPctInput(num, 0, 100)/100*210
  } else if(prop==='w'){
    cur.w = clampPctInput(num, 1, 100)/100*297
  } else if(prop==='h'){
    cur.h = clampPctInput(num, 1, 100)/100*210
  }
  hasUnsaved.value=true
}
function onSidebarFontChange(key, val){
  const pt=parseFloat(val); if(isNaN(pt)||pt<4||pt>80) return
  if(!certLayout.value[key]) certLayout.value[key]={}
  certLayout.value[key].font_pt=pt
  hasUnsaved.value=true
}
// drag handlers (pointer events on stage rect)
function onFieldPointerDown(e, key){
  if(!editMode.value) return
  if(lockedFields.value.has(key)) return
  if(e.target.closest('.rz-handle')) return // let resize handle own it
  e.preventDefault()
  selectedField.value=key
  const rect=certStageRef.value?.getBoundingClientRect(); if(!rect) return
  const cur=certLayout.value[key]; if(!cur) return
  dragState.value={key, startX:e.clientX, startY:e.clientY, initX:cur.x, initY:cur.y}
  const onMove=(ev)=>{
    if(!dragState.value) return
    const dx=(ev.clientX-dragState.value.startX)/rect.width*297
    const dy=(ev.clientY-dragState.value.startY)/rect.height*210
    const isCentered=!['ttd','qr'].includes(key)
    let nx=dragState.value.initX+dx
    let ny=dragState.value.initY+dy
    const wMm=cur.w ?? DEFAULT_LAYOUT[key].w ?? 60
    const minX=isCentered? wMm/2 : 0
    const maxX=isCentered? 297-wMm/2 : 297-wMm
    nx=Math.max(minX, Math.min(maxX, nx))
    ny=Math.max(4, Math.min(206, ny))
    certLayout.value[key].x=+nx.toFixed(1)
    certLayout.value[key].y=+ny.toFixed(1)
    hasUnsaved.value=true
  }
  const onUp=()=>{
    dragState.value=null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
  }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}
function onResizePointerDown(e, key, dir){
  if(lockedFields.value.has(key)) return
  e.preventDefault(); e.stopPropagation()
  selectedField.value=key
  const rect=certStageRef.value?.getBoundingClientRect(); if(!rect) return
  const cur=certLayout.value[key]; if(!cur) return
  const isCentered=!['ttd','qr'].includes(key)
  const startX=e.clientX, startY=e.clientY
  const initX=cur.x, initY=cur.y, initW=cur.w ?? DEFAULT_LAYOUT[key].w ?? 60, initH=cur.h ?? 4
  resizeState.value={key,dir,startX,startY,initX,initY,initW,initH}
  const onMove=(ev)=>{
    const dx=(ev.clientX-startX)/rect.width*297
    const dy=(ev.clientY-startY)/rect.height*210
    const c=certLayout.value[key]; if(!c) return
    let nw=initW, nh=initH, nx=initX, ny=initY
    if(dir.includes('e')) nw=Math.max(10, initW+dx)
    if(dir.includes('w')){ nw=Math.max(10, initW-dx); if(!isCentered) nx=initX+dx; else nx=initX+dx/2 }
    if(dir.includes('s')){ if(c.h!=null || key==='qr') nh=Math.max(6, initH+dy); else { /* height for text fields: ignore or treat as font scale */ } }
    if(dir.includes('n')){ if(c.h!=null || key==='qr') { nh=Math.max(6, initH-dy); ny=initY+dy } }
    // clamp width
    nw=Math.min(280, nw)
    if(nh) nh=Math.min(190, nh)
    c.w=+nw.toFixed(1)
    if(c.h!=null || key==='qr') c.h=+nh.toFixed(1)
    if(!isCentered && (dir.includes('w')||dir.includes('e'))){
      // for ttd/qr, x tracks left edge, already handled; for w-dir adjust x, for e-dir x unchanged
    } else if(isCentered){
      c.x=+nx.toFixed(1)
    }
    if(dir.includes('n') && (c.h!=null || key==='qr')) c.y=+ny.toFixed(1)
    hasUnsaved.value=true
  }
  const onUp=()=>{
    resizeState.value=null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
  }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}
function clampPctInput(v, min, max){ const n=parseFloat(v); if(isNaN(n)) return min; return Math.max(min, Math.min(max, n)) }
function onBgImgError(){ if(bgSrc.value.includes('/api/cert_bg')){ bgLoadError.value=true } else { bgSrc.value='/api/cert_bg' } }
function retryBg(){ bgSrc.value='/api/uploads/cert_bg/cert_bg.png?t='+Date.now(); bgLoadError.value=false; loadBgMeta(bgSrc.value) }
function loadBgMeta(url){
  try{
    const clean=url.split('?')[0]
    // try fetch settings first if available via Image
    const img=new Image()
    img.onload=()=>{
      const w=img.naturalWidth, h=img.naturalHeight
      const aspect=h? +(w/h).toFixed(4): null
      bgMeta.value={w,h,aspect,mime:bgMeta.value.mime||'image/png',size:bgMeta.value.size||null}
    }
    img.onerror=()=>{}
    // bust cache for measurement: use url as-is
    img.src=url
  }catch{}
}


const genUserId=ref(null), genUserNama=ref(''), genUserQ=ref('')

const showTarget=ref(false), showUser=ref(false)

// event-only: ekskulList dipertahankan kosong (tidak di-fetch)
const ekskulList=ref([]), eventList=ref([]), userList=ref([])
const eventPeserta=ref([]), pesertaLoading=ref(false)

const generating=ref(false), genMsg=ref(''), genOk=ref(true), lastCert=ref(null)

// Opsi C save-before-print: snapshot template per-event (GET /sertifikat/template/event/{id})
const tplLoadedId=ref(null), tplFontsSnap=ref(null), tplLayoutSnap=ref(null), tplLoading=ref(false), tplSaving=ref(false)

const toast=ref(''), toastOk=ref(true)

const showPreview=ref(false), previewUrl=ref(''), previewLoading=ref(false), previewNomor=ref(''), previewBlob=ref(null)
// Opsi A scale-to-fit: blob URL internal -> tambah fragment view=Fit agar toolbar PDF fit halaman
const pdfPreviewSrc=computed(()=> previewUrl.value ? previewUrl.value + '#view=Fit' : '')
// Opsi A: scale-to-fit sertifikat 297mm (~1122px) ke lebar container
const CERT_DESIGN_W = 1122 // 297mm @96dpi
const CERT_DESIGN_H = Math.round(CERT_DESIGN_W * 210 / 297) // ~794
const certLiveWrap=ref(null), certLiveInner=ref(null), certModalWrap=ref(null), certModalInner=ref(null)
function fitOne(wrap, inner){
  if(!wrap || !inner) return
  const w = wrap.clientWidth
  if(!w) return
  const scale = w / CERT_DESIGN_W
  inner.style.transform = `scale(${scale})`
  wrap.style.height = Math.round(CERT_DESIGN_W ? (w * 210 / 297) : 0) + 'px'
}
function fitCertPreview(){
  fitOne(certLiveWrap.value, certLiveInner.value)
  if(showPreview.value && previewTab.value==='html') fitOne(certModalWrap.value, certModalInner.value)
}
let certRO=null
watch([showPreview, previewTab, previewModalHtml], ()=>{ nextTick(fitCertPreview) })
watch(previewHtml, ()=>{ nextTick(fitCertPreview) })

const filteredTargets=computed(()=>{

  const list=eventList.value

 const qq=genTargetQ.value.trim().toLowerCase()

 if(!qq) return list.slice(0,12)

 return list.filter(x=>x.nama.toLowerCase().includes(qq)).slice(0,12)

})

const filteredUsers=computed(()=>{
 const qq=genUserQ.value.trim().toLowerCase()
 // mode per-event: filter lokal dari cache peserta (sudah merge hadir)
 if(genTargetId.value){
   const list=eventPeserta.value.map(p=>({id:p.user_id, nama:p.nama, email:p.email, kelas:p.kelas||'', hadir: p._hadir ?? (Number(p.hadir||0)===1), sesi_hadir: p._sesi_hadir||0, total_sesi: p._total_sesi||0, has_cert: p._has_cert, existing_nomor: p._existing_nomor||''}))
   // sort: hadir dulu, lalu nama
   list.sort((a,b)=> ((b.hadir?1:0)-(a.hadir?1:0)) || String(a.nama||'').localeCompare(String(b.nama||'')))
   if(!qq) return list.slice(0,50)
   return list.filter(u=>(u.nama||'').toLowerCase().includes(qq)||(u.email||'').toLowerCase().includes(qq)).slice(0,20)
 }
 if(qq.length<2) return []
 return userList.value.filter(u=>u.nama.toLowerCase().includes(qq)||u.email.toLowerCase().includes(qq)).slice(0,10)
})
const userEmptyMsg=computed(()=>{
 if(genTargetId.value){
   if(pesertaLoading.value) return 'Memuat peserta...'
   if(!eventPeserta.value.length) return 'Tidak ada siswa terdaftar di event ini'
   if(genUserQ.value.trim()) return 'Tidak cocok, coba kata lain'
   return 'Tidak ada siswa terdaftar di event ini'
 }
 return 'Pilih target event dulu atau ketik minimal 2 huruf'
})

const canGen=computed(()=> genUserId.value && genTargetId.value)
// Opsi C: dirty check certFonts/certLayout vs snapshot template event
const fontsDirty=computed(()=>{
  try{ return JSON.stringify(certFonts.value || {}) !== JSON.stringify(tplFontsSnap.value || {}) }catch{ return true }
})
function layoutObjForSnap(){
  const o={}
  for(const k of FIELD_KEYS) o[k]=certLayout.value?.[k]
  return o
}
const layoutDirty=computed(()=>{
  if(hasUnsaved.value) return true
  try{ return JSON.stringify(layoutObjForSnap()) !== JSON.stringify(tplLayoutSnap.value || {}) }catch{ return true }
})
const needSave=computed(()=> tplLoadedId.value!==genTargetId.value || !tplFontsSnap.value || fontsDirty.value || layoutDirty.value)
const saveHint=computed(()=>{
  if(!genTargetId.value) return ''
  if(tplLoading.value) return 'Memuat template event...'
  if(tplLoadedId.value!==genTargetId.value || !tplFontsSnap.value) return 'Wajib Simpan Template Event dulu sebelum Cetak'
  if(fontsDirty.value || layoutDirty.value) return 'Ada perubahan font/layout belum disimpan — Wajib Simpan Template Event dulu sebelum Cetak'
  return ''
})
const selUser=computed(()=> genTargetId.value && genUserId.value ? eventPeserta.value.find(p=> String(p.user_id)===String(genUserId.value)) : null)
const genBlockReason=computed(()=>{
  if(!genTargetId.value || !genUserId.value) return ''
  const p=selUser.value
  if(!p) return ''
  const hadir=p._hadir ?? (Number(p.hadir||0)===1)
  return hadir ? '' : 'Belum hadir — generate akan ditolak backend (409)'
})

function onTargetInput(){ showTarget.value=true }

function onUserInput(){ showUser.value=true; if(genTargetId.value) return; const term=genUserQ.value.trim(); if(term.length<2) return; clearTimeout(userDebounce); userDebounce=setTimeout(()=>fetchUsers(term),300) }

function onUserFocus(){ showUser.value=true }

async function pickTarget(t){ genTargetId.value=t.id; genTargetNama.value=t.nama; genTargetQ.value=t.nama; showTarget.value=false; genUserId.value=null; genUserNama.value=''; genUserQ.value=''; userList.value=[]; eventPeserta.value=[]; await fetchEventPeserta(t.id); fetchHadirCache(t.id); loadEventTemplate(t.id) }

// Opsi C: fetch template per-event, snap fonts+layout; 404/empty -> snap null (belum-save)
// + sinkron preview agar PDF = preview (fix bug 1: gold line mismatch)
async function loadEventTemplate(eid){
  if(!eid){
    tplLoadedId.value=null; tplFontsSnap.value=null; tplLayoutSnap.value=null
    // kembali ke global layout
    if(globalCertLayoutSnap.value){
      try{ certLayout.value = JSON.parse(JSON.stringify(globalCertLayoutSnap.value)); hasUnsaved.value=false }catch{}
    }
    return
  }
  tplLoading.value=true
  try{
    const j=await api('/sertifikat/template/event/'+eid)
    let srv=j.data?.fonts_parsed || null
    if(!srv && typeof j.data?.fonts_json==='string' && j.data.fonts_json.trim()){ try{ srv=JSON.parse(j.data.fonts_json) }catch{} }
    if(!srv){ const t=j.data?.template||null; if(t && typeof t.fonts_json==='string' && t.fonts_json.trim()){ try{ srv=JSON.parse(t.fonts_json) }catch{} } }
    tplFontsSnap.value=(srv && typeof srv==='object') ? JSON.parse(JSON.stringify(srv)) : null
    const lp=j.data?.layout_parsed || null
    const useCustom = Number(j.data?.use_custom_layout ?? j.data?.template?.use_custom_layout ?? 0)===1
    tplLayoutSnap.value=(lp && typeof lp==='object' && Object.keys(lp).length) ? JSON.parse(JSON.stringify(lp)) : null
    const hasAny = !!(tplFontsSnap.value || tplLayoutSnap.value || useCustom)
    if(!hasAny){ tplLoadedId.value=eid; return } // event exists tapi belum ada template — tetap pakai global preview
    if(tplFontsSnap.value && applyServerFonts(tplFontsSnap.value)){ try{ localStorage.setItem('certFonts', JSON.stringify(certFonts.value)) }catch{} }
    // jika event punya custom layout, tampilkan di editor agar preview identik PDF
    if(useCustom && tplLayoutSnap.value){
      const next=cloneLayout(DEFAULT_LAYOUT)
      for(const k of FIELD_KEYS){ if(tplLayoutSnap.value[k] && typeof tplLayoutSnap.value[k]==='object') next[k]={...next[k], ...tplLayoutSnap.value[k]} }
      certLayout.value = next
      hasUnsaved.value=false
    } else if(globalCertLayoutSnap.value){
      // tidak custom — pakai global
      try{ certLayout.value = JSON.parse(JSON.stringify(globalCertLayoutSnap.value)); hasUnsaved.value=false }catch{}
    }
    tplLoadedId.value=eid
  }catch{
    tplLoadedId.value=null; tplFontsSnap.value=null; tplLayoutSnap.value=null
    if(globalCertLayoutSnap.value){ try{ certLayout.value = JSON.parse(JSON.stringify(globalCertLayoutSnap.value)); hasUnsaved.value=false }catch{} }
  }
  finally{ tplLoading.value=false }
}
// Opsi C: save template event tiru SesiCetak.saveTemplate (tanpa deskripsi_override/cert_bg_url)
async function saveEventTemplate(fromGlobalSync=false){
  if(!genTargetId.value) return
  tplSaving.value=true; if(!fromGlobalSync) certLayoutMsg.value=''
  try{
    const edited=hasUnsaved.value || layoutDirty.value || fromGlobalSync
    const snap = layoutObjForSnap()
    const payload={
      label_default: genLabelFinal.value,
      use_custom_layout: edited ? 1 : 0,
      fonts_json: JSON.stringify(certFonts.value || DEFAULT_FONTS),
      layout_json: edited ? JSON.stringify(snap) : null,
    }
    await api('/sertifikat/template/event/'+genTargetId.value, {method:'POST', body: payload})
    tplFontsSnap.value=JSON.parse(JSON.stringify(certFonts.value || DEFAULT_FONTS))
    if(edited) tplLayoutSnap.value=JSON.parse(JSON.stringify(snap))
    tplLoadedId.value=genTargetId.value
    hasUnsaved.value=false
    if(!fromGlobalSync){
      certLayoutOk.value=true; certLayoutMsg.value='Template event tersimpan ✓ — tombol Cetak aktif'
      setTimeout(()=>certLayoutMsg.value='',2500)
    }
  }catch(err){
    if(!fromGlobalSync){
      certLayoutOk.value=false; certLayoutMsg.value=err?.error?.message||err?.message||'Gagal simpan template event'
      setTimeout(()=>certLayoutMsg.value='',3000)
    } else throw err
  }finally{ tplSaving.value=false }
}

// event-only: filter tipe dihapus, selalu event
function onTipeChange(){ page.value=1; fetchList() }

function onEventFilterChange(){

  page.value=1; fetchList()

}

function resetFilters(){ filterTipe.value='event'; filterEkskulId.value=''; filterEventId.value=''; q.value=''; page.value=1; fetchList() }

let debounceTimer=null

function debounceFetchList(){ clearTimeout(debounceTimer); debounceTimer=setTimeout(()=>{page.value=1;fetchList()},300)}

async function fetchList(){

 loading.value=true

 try{

 const p=new URLSearchParams({page:page.value,limit})

  if(filterEventId.value) p.set('event_id',filterEventId.value)

 if(q.value.trim()) p.set('q',q.value.trim())

 const j=await api('/sertifikat?'+p.toString())

  rows.value=j.data||[]; total.value=j.meta?.total||rows.value.length
  // ponytail: seleksi persist cross-page; hanya prune yang baru terhapus (doDelete/doBulkDelete).

  }catch(e){ }finally{loading.value=false}

}

async function fetchInit(){

  // event-only: tidak fetch /ekskul lagi
  try{ const b=await api('/events?limit=100'); eventList.value=b.data||[] }catch{}

 fetchList()

}

let userDebounce=null, userReqId=0, pesertaReqId=0
// Opsi C: cache hadir QR+manual per event + single-flight
const hadirCache=new Map(), hadirInflight=new Map()
async function fetchHadirCache(eid){
  if(!eid) return
  if(hadirCache.has(eid)){ mergeHadir(eid, hadirCache.get(eid)); return }
  if(hadirInflight.has(eid)){ try{ await hadirInflight.get(eid) }catch{} return }
  const key=String(eid)
  const p=(async()=>{
    try{
      // rekap default from/to = bulan ini + filter DATE(created_at) → peserta lama hilang;
      // paksa from=1970-01-01 (to default = hari ini) + loop page (limit backend max 100)
      const qrById={}
      let pg=1
      for(;;){
        const rk=await api('/laporan/rekap?event_id='+key+'&session_id=all&limit=100&page='+pg+'&from=1970-01-01').catch(()=>null)
        const rows=rk?.data?.event?.rows||[]
        for(const r of rows) qrById[String(r.id)]={sesi_hadir: r.sesi_hadir||0, total_sesi: r.total_sesi||0}
        const total=rk?.data?.event?.total ?? rows.length
        if(rows.length<100 || Object.keys(qrById).length>=total || pg>=20) break
        pg++
      }
      const elig=await api('/sertifikat/eligible?tipe=event&target_id='+key).catch(()=>null)
      const certById={}
      for(const u of (elig?.data || [])) certById[String(u.user_id)]={has_cert: !!u.has_cert, existing_nomor: u.existing_nomor||''}
      const merged={qrById, certById}
      hadirCache.set(key, merged); mergeHadir(key, merged)
    }catch{ /* badge optional — gagal fetch = badge tidak tampil, tidak blokir */ }
    finally{ hadirInflight.delete(key) }
  })()
  hadirInflight.set(key, p)
  try{ await p }catch{}
}
function mergeHadir(eid, merged){
  if(String(genTargetId.value)!==String(eid)) return
  const {qrById={}, certById={}}=merged||{}
  for(const p of eventPeserta.value){
    const k=String(p.user_id)
    const qr=qrById[k]||{}
    const c=certById[k]||{}
    p._hadirManual=Number(p.hadir||0)===1
    p._sesi_hadir=Number(qr.sesi_hadir||0)
    p._total_sesi=Number(qr.total_sesi||0)
    p._hadir=p._hadirManual || p._sesi_hadir>0
    p._has_cert=!!c.has_cert
    p._existing_nomor=c.existing_nomor||''
  }
}
function refreshEligibleCache(){ if(genTargetId.value){ hadirCache.delete(String(genTargetId.value)); fetchHadirCache(genTargetId.value) } }
function pickUser(u){ if(genTargetId.value && u.hadir===false) return; genUserId.value=u.id; genUserNama.value=u.nama; genUserQ.value=u.nama; showUser.value=false }

async function fetchEventPeserta(eid){
 const my=++pesertaReqId; pesertaLoading.value=true
 try{ const j=await api('/events/'+eid+'/peserta'); if(my!==pesertaReqId) return; eventPeserta.value=j.data||[] }catch{ if(my===pesertaReqId) eventPeserta.value=[] }finally{ if(my===pesertaReqId) pesertaLoading.value=false }
}

async function fetchUsers(term){
 const my=++userReqId
 try{ const j=await api('/users?q='+encodeURIComponent(term)+'&limit=20'); if(my!==userReqId) return; userList.value=j.data||[] }catch{ }
}

async function doGenerate(){

  if(needSave.value){ genOk.value=false; genMsg.value=saveHint.value || 'Wajib Simpan Template Event dulu sebelum Cetak'; toast.value=genMsg.value; toastOk.value=false; setTimeout(()=>toast.value='',3000); return }
  genMsg.value=''; generating.value=true

 try{

  const j=await api('/sertifikat/generate',{method:'POST',body:{user_id:genUserId.value, tipe:'event', target_id:genTargetId.value, label: genLabelFinal.value}})

 genOk.value=true; genMsg.value='Berhasil - '+j.data.nomor

 lastCert.value=j.data

  toast.value='Sertifikat terbit: '+j.data.nomor; toastOk.value=true; setTimeout(()=>toast.value='',2200)

  hadirCache.clear(); refreshEligibleCache(); fetchList()

 }catch(e){

 const err=e.error||e

 if(err.code==='EXISTS'){

 genOk.value=false; genMsg.value='Sudah ada: '+ (err.nomor||err.hash||'duplicate')

 if(err.hash) lastCert.value={id:err.id, hash:err.hash, nomor:err.nomor||'-'}

 } else if(err.code==='NOT_ELIGIBLE'){

 genOk.value=false; genMsg.value='Belum eligible: '+err.message

 } else {

 genOk.value=false; genMsg.value=err.message||'Gagal generate'

 }

 toast.value=genMsg.value; toastOk.value=false; setTimeout(()=>toast.value='',3000)

 }finally{generating.value=false}

}

function pdfFontQuery(){
  // Kirim semua font terpilih agar PDF identik preview (walau DejaVu juga dikirim — backend pickFont sudah benar).
  // Sebelum fix: DejaVu di-skip → query kosong → backend fallback blank; sekarang query eksplisit lebih aman.
  const f=certFonts.value||DEFAULT_FONTS
  const p=new URLSearchParams()
  const push=(k,v)=>{ v=String(v||'').trim(); if(v) p.set(k,v) }
  push('font_nama',f.nama); push('font_label',f.label); push('font_deskripsi',f.deskripsi)
  push('font_nomor',f.nomor); push('font_ttd_nama',f.ttd_nama)
  const s=p.toString(); return s ? '?'+s : ''
}
async function previewPdf(id){

  const row=rows.value.find(x=>String(x.id)===String(id)) || lastCert.value

  previewNomor.value=row?.nomor||('sertifikat-'+id)
  previewId.value=id

  showPreview.value=true; previewLoading.value=true; previewUrl.value=''; previewBlob.value=null; previewTab.value='html'

 try{

  const csrf=await getCsrf()||''

  const res=await fetch('/api/sertifikat/'+id+'/pdf'+pdfFontQuery(),{credentials:'include', headers:{'X-CSRF-Token': csrf}})

  if(!res.ok){ const j=await res.json().catch(()=>({error:{message:res.statusText}})); throw j }

  try{ const w=res.headers.get('X-Cert-Font-Warnings'); if(w){ toast.value='PDF fallback font: '+w; toastOk.value=false; setTimeout(()=>toast.value='',4000) } }catch{}

  const blob=await res.blob()

  previewBlob.value=blob

 const url=URL.createObjectURL(blob)

 if(previewUrl.value) try{ URL.revokeObjectURL(previewUrl.value) }catch{}

 previewUrl.value=url

 }catch(e){ toast.value=e.error?.message||e.message||'Gagal preview PDF'; toastOk.value=false; setTimeout(()=>toast.value='',2500) }

 finally{ previewLoading.value=false }

}

function closePreview(){

 showPreview.value=false

 if(previewUrl.value) try{ URL.revokeObjectURL(previewUrl.value) }catch{}

 previewUrl.value=''; previewBlob.value=null

}

function downloadFromPreview(){

 if(!previewBlob.value || !previewUrl.value) return

 const a=document.createElement('a'); a.href=previewUrl.value; a.download=(previewNomor.value||'sertifikat')+'.pdf'; document.body.appendChild(a); a.click(); a.remove()

}

async function openPdf(id){

 try{

  const csrf=await getCsrf()||''

  const res=await fetch('/api/sertifikat/'+id+'/pdf'+pdfFontQuery(),{credentials:'include', headers:{'X-CSRF-Token': csrf}})

  if(!res.ok){ const j=await res.json().catch(()=>({error:{message:res.statusText}})); throw j }

  try{ const w=res.headers.get('X-Cert-Font-Warnings'); if(w){ toast.value='PDF fallback font: '+w; toastOk.value=false; setTimeout(()=>toast.value='',4000) } }catch{}

  const blob=await res.blob()

  const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download='sertifikat-'+id+'.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),2000)

 }catch(e){ toast.value=e.error?.message||'Gagal download PDF'; toastOk.value=false; setTimeout(()=>toast.value='',2500) }

}

function copyVerify(hash){

 const url=location.origin+'/verify/'+hash

 navigator.clipboard.writeText(url).then(()=>{ toast.value='Link verify disalin'; toastOk.value=true; setTimeout(()=>toast.value='',1800)}).catch(()=>{ prompt('Copy link verify:',url)})

}

// Opsi C: delete single + bulk sertifikat
const selectedIds=ref(new Set()), bulkDeleting=ref(false)
const allSelected=computed(()=> rows.value.length>0 && rows.value.every(r=> selectedIds.value.has(r.id)))
function toggleOne(id, on){ const s=new Set(selectedIds.value); const k=String(id); let found=null; for(const v of s){ if(String(v)===k){ found=v; break } } if(on) s.add(found??id); else if(found!==null) s.delete(found); selectedIds.value=s }
// toggleAll = select-all halaman ini (tambah ke Set), uncheck = lepas halaman ini saja
function toggleAll(on){ const s=new Set(selectedIds.value); if(on){ for(const r of rows.value) s.add(r.id) } else { for(const r of rows.value){ for(const v of [...s]){ if(String(v)===String(r.id)) s.delete(v) } } } selectedIds.value=s }
async function doDelete(r){
  if(!confirm(`Hapus sertifikat ${r.nomor} (${r.user_nama})?`)) return
  const prev=[...rows.value]
  rows.value=rows.value.filter(x=> x.id!==r.id)
  try{
    await api('/sertifikat/'+r.id, {method:'DELETE'})
    const s=new Set(selectedIds.value); for(const v of [...s]){ if(String(v)===String(r.id)) s.delete(v) } selectedIds.value=s
    toast.value='Sertifikat dihapus: '+r.nomor; toastOk.value=true; setTimeout(()=>toast.value='',2200)
    hadirCache.clear(); refreshEligibleCache(); fetchList()
  }catch(e){
    if(e?._status===404){ const s=new Set(selectedIds.value); for(const v of [...s]){ if(String(v)===String(r.id)) s.delete(v) } selectedIds.value=s; toast.value='Sudah tidak ada di server: '+r.nomor; toastOk.value=true; setTimeout(()=>toast.value='',2200); hadirCache.clear(); refreshEligibleCache(); fetchList(); return }
    rows.value=prev; toast.value=e?.error?.message||'Gagal hapus'; toastOk.value=false; setTimeout(()=>toast.value='',2500) }
}
async function doBulkDelete(){
  const ids=[...selectedIds.value]
  if(!ids.length) return
  if(!confirm(`Hapus ${ids.length} sertifikat terpilih?`)) return
  bulkDeleting.value=true
  try{
    let deleted=0, missing=0, failed=0, fallback=false
    try{ const j=await api('/sertifikat/delete-batch', {method:'POST', body:{ids}}); deleted=Number(j.deleted??ids.length); missing=Number(j.missing??0) }
    catch(e){
      if(e?._status!==404) throw e
      fallback=true
      for(const id of ids){ try{ await api('/sertifikat/'+id, {method:'DELETE'}); deleted++ }catch(ie){ if(ie?._status===404) deleted++; else failed++ } }
    }
    // ponytail: full=true kalau bulk lolos; fallback per-id tanpa missing info.
    const ok=fallback ? failed===0 : missing===0
    toast.value= fallback ? `${deleted} dihapus${failed>0 ? `, ${failed} gagal` : ''}` : (missing>0 ? `${deleted} dihapus, ${missing} tidak ditemukan` : `${deleted} sertifikat dihapus`)
    toastOk.value=ok; setTimeout(()=>toast.value='',2200)
    selectedIds.value=new Set(); hadirCache.clear(); refreshEligibleCache(); fetchList()
  }catch(e){ toast.value=e?.error?.message||'Gagal hapus massal'; toastOk.value=false; setTimeout(()=>toast.value='',2500) }
  finally{ bulkDeleting.value=false }
}

function openBgPicker(){ try{ bgInput.value && bgInput.value.click() }catch{} }
async function onBgFile(e){
  const f=e.target?.files?.[0]; if(!f) return
  if(f.size>4*1024*1024){ bgOk.value=false; bgMsg.value='Maks 4MB'; setTimeout(()=>bgMsg.value='',2500); e.target.value=''; return }
  bgLoading.value=true; bgMsg.value=''
  try{
    const fd=new FormData(); fd.append('cert_bg', f); fd.append('bg', f)
    const csrf=await getCsrf()
    let res=await fetch('/api/admin/cert_bg',{method:'POST', credentials:'include', headers:{'X-CSRF-Token': csrf||''}, body: fd})
    if(!res.ok){
      // fallback coba /api/settings/cert_bg
      const fd2=new FormData(); fd2.append('cert_bg', f)
      res=await fetch('/api/settings/cert_bg',{method:'POST', credentials:'include', headers:{'X-CSRF-Token': csrf||''}, body: fd2})
    }
    const j=await res.json().catch(()=>({success:res.ok, error:{message:res.statusText}}))
    if(!res.ok || j.success===false) throw j
    bgOk.value=true; bgMsg.value='Background terupload -- PDF selanjutnya pakai bg baru (base64 embed)'
    bgLoadError.value=false
    // adaptif: update meta dari response
    if(j.w && j.h){ bgMeta.value={w:j.w,h:j.h,aspect:j.aspect||null,mime:j.mime||'image/png',size:j.size||null} }
    if(j.url) bgSrc.value=j.url; else bgSrc.value='/api/uploads/cert_bg/cert_bg.png?t='+Date.now()
    setTimeout(()=>bgMsg.value='',2500)
  }catch(err){
    bgOk.value=false; bgMsg.value=err?.error?.message||err?.message||'Endpoint belum ada -- copy manual ke api/uploads/cert_bg/cert_bg.png'
    setTimeout(()=>bgMsg.value='',4000)
  }finally{ bgLoading.value=false; if(e.target) e.target.value='' }
}

onMounted(()=>{ fetchInit(); fetchTtdSettings(); loadCertLayout(); loadBgMeta(bgSrc.value); nextTick(fitCertPreview); try{ certRO=new ResizeObserver(()=>fitCertPreview()); if(certLiveWrap.value) certRO.observe(certLiveWrap.value); if(certModalWrap.value) certRO.observe(certModalWrap.value); window.addEventListener('resize', fitCertPreview) }catch{}; document.addEventListener('click',e=>{ if(!e.target.closest('.combo')){ showTarget.value=false; showUser.value=false }})})
onBeforeUnmount(()=>{ try{ certRO && certRO.disconnect() }catch{}; try{ window.removeEventListener('resize', fitCertPreview) }catch{} })

</script>

<style scoped>

.kat-page{

 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;

 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;

 background:var(--m-bg);color:var(--m-ink);

 margin:-24px calc(50% - 50vw) 0;padding:20px max(16px,calc(50vw - 680px)) 24px;

}

.kat-inner{max-width:1360px;margin:0 auto}

.mono{font-family:'Satoshi',system-ui,sans-serif}

.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}

.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em}

.kat-sub{margin:2px 0 0;font-size:11.5px;color:var(--m-muted);font-family:'Satoshi',system-ui,sans-serif;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}

.kat-link{padding:8px 14px;border-radius:10px;border:1px solid var(--m-line);background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;gap:6px;cursor:pointer}

.kat-link:hover{border-color:#d4d4d8}

.kat-strip{margin:0 0 12px;font-size:12.5px;background:#fff;border:1px solid var(--m-line);border-left:3px solid var(--m-cta);border-radius:8px;padding:8px 12px}

.kat-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}

@media(max-width:900px){ .kat-stats{grid-template-columns:repeat(2,1fr)} }

.preview-cert-wrap{width:100%;aspect-ratio:297/210;position:relative;overflow:hidden;border:1.5px solid var(--m-line);border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.06);background:#fff}
.cert-preview-iframe{width:100%;aspect-ratio:297/210;border:none;display:block;background:#fff;border-radius:14px}
/* Opsi A scale-to-fit: body srcdoc tetap 297mm, di-scale dari luar agar header tidak kepotong */
.cert-scale-wrap{width:100%;overflow:hidden;position:relative;background:#fff;border-radius:14px;aspect-ratio:297/210}
.cert-scale-inner{width:1122px;transform-origin:top left}
.cert-preview-iframe.cert-scaled{width:1122px;height:794px;border:none;display:block;background:#fff;border-radius:14px}
.cert-scale-modal{border:1px solid var(--m-line)}
.preview-cert-overlay{position:absolute;inset:0;z-index:2;pointer-events:none}
.preview-cert-overlay .ov-field{position:absolute;pointer-events:auto}
.cert-bg-grid{display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start}
.cert-bg-preview{width:280px; aspect-ratio:297/210; border:1px dashed var(--m-line); border-radius:12px; overflow:hidden; background:#fafafa; display:grid; place-items:center}
.cert-bg-actions{flex:1;min-width:260px}
.cert-live-preview{margin-top:4px}
.preview-tabs{border-bottom:1px solid var(--m-line); padding-bottom:8px}
@media(max-width:900px){ .cert-bg-grid{grid-template-columns:1fr} .cert-bg-preview{width:100%} }
@media(max-width:640px){ .kat-stats{grid-template-columns:1fr 1fr} .kat-head{flex-direction:column;align-items:flex-start} }

.kat-stat{background:#fff;border:1px solid var(--m-line);border-radius:14px;padding:12px 14px}

.kat-stat-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.kat-stat-value{font-size:22px;font-weight:800;letter-spacing:-.02em;margin-top:2px}

.kat-stat-desc{font-size:11px;color:var(--m-muted);margin-top:2px}

.kat-card{background:#fff;border:1px solid var(--m-line);border-radius:16px;overflow:hidden}

.cert-card{padding:16px;margin-bottom:12px}

.cert-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}

.cert-kicker{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted)}

.cert-card-title{font-size:15px;font-weight:800;letter-spacing:-.01em;margin-top:2px}

.cert-card-sub{font-size:11px;color:var(--m-muted);margin-top:2px;line-height:1.5}

.mono-pill{background:#F1F5F4;border:1px solid var(--m-line);padding:1px 6px;border-radius:999px;font-size:10.5px}

.cert-badge{font-size:11px;font-weight:700;padding:6px 10px;border-radius:999px;border:1px solid var(--m-line);background:#fff;color:var(--m-muted);flex-shrink:0}

.cert-badge.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.cert-ttd-grid{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start}

.cert-ttd-left{flex:1;min-width:260px}

.cert-preview{width:280px;min-height:120px;border:1px dashed var(--m-line);border-radius:12px;display:grid;place-items:center;background:#fafafa;overflow:hidden;padding:8px}

.cert-preview-img{max-width:100%;max-height:110px;object-fit:contain;display:block}

.lbl{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);display:block;margin-bottom:6px}

.kat-input{width:100%;padding:10px 12px;border:1px solid var(--m-line);border-radius:12px;background:#fff;font-size:13px;box-sizing:border-box;color:var(--m-ink);outline:none}

.kat-input:focus{border-color:var(--m-ink);box-shadow:0 0 0 3px rgba(47,62,70,.08)}

.kat-select{padding:10px 12px;border:1px solid var(--m-line);border-radius:12px;background:#fff;font-size:13px;color:var(--m-ink);min-height:38px}

.combo{position:relative}

.combo-list{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid var(--m-line);border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.08);max-height:220px;overflow:auto;z-index:20;list-style:none;margin:0;padding:4px}

.combo-opt{padding:8px 10px;border-radius:8px;cursor:pointer;font-size:13px}

.combo-opt:hover{background:var(--m-bg)}

.combo-opt.muted{color:var(--m-muted);cursor:default}

.combo-opt.user-opt{display:flex;justify-content:space-between;align-items:center;gap:8px}
.combo-opt.user-opt .u-badges{display:flex;gap:6px;align-items:center;flex-shrink:0}
.combo-opt.opt-disabled{opacity:.55;cursor:not-allowed}
.pill.hadir-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.pill.hadir-no{background:#f4f4f5;border-color:var(--m-line);color:#6B7C85}
.pill.sudah{background:#fffbeb;border-color:#fde68a;color:#92400e}

.gen-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.gen-grid .field:nth-child(4), .gen-grid .field:nth-child(5){grid-column:span 1}
@media(max-width:1100px){ .gen-grid{grid-template-columns:1fr 1fr} }
@media(max-width:640px){ .gen-grid{grid-template-columns:1fr} }

@media(max-width:900px){.gen-grid{grid-template-columns:1fr}}

.gen-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}

.kat-cta{padding:10px 18px;border-radius:999px;background:var(--m-ink);color:#fff;font-size:13px;font-weight:700;border:1px solid var(--m-ink);cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}

.kat-cta:hover{background:#1a2a33}

.kat-cta:disabled{opacity:.45;cursor:not-allowed}

.kat-mini{padding:6px 12px;border-radius:999px;border:1px solid var(--m-line);background:#fff;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--m-ink);display:inline-flex;align-items:center;flex-shrink:0;white-space:nowrap}

.kat-mini:hover{border-color:#d4d4d8}

.kat-mini.strong{background:var(--m-ink);color:#fff;border-color:var(--m-ink)}

.kat-mini.strong:hover{background:#1a2a33}

.kat-mini.neutral{color:var(--m-ink)}

.alert{margin-top:10px;padding:10px 12px;border-radius:12px;font-size:12px;border:1px solid var(--m-line)}

.alert-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.alert-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}

.last-cert{margin-top:12px;padding:10px 12px;background:#fafafa;border:1px solid var(--m-line);border-radius:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px}

.last-cert-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

.table-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--m-line)}
.table-head-l{display:flex;flex-direction:column;gap:3px;min-width:140px}
.table-title{font-size:13px;font-weight:800;letter-spacing:-.01em}
.table-sub{font-size:11px;color:var(--m-muted);margin-top:2px}
.table-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end;flex:1 1 auto}
.table-filter-select{flex:0 1 200px;min-width:150px;max-width:240px}
.table-filter-search{flex:1 1 220px;min-width:170px;max-width:320px}
.table-filter-reset{white-space:nowrap}
.kat-search{position:relative;flex:0 1 260px;min-width:200px}

.kat-search.small .kat-input{padding:9px 36px 9px 34px;border-radius:10px}

.kat-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--m-muted)}

.kat-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:24px;height:24px;border-radius:999px;border:1px solid var(--m-line);background:#fff;cursor:pointer;color:var(--m-muted);line-height:1;display:grid;place-items:center}

.kat-clear:hover{background:var(--m-bg)}

.table-wrap{overflow:auto}

table{width:100%;border-collapse:collapse;font-size:13px}

thead{background:#fafafa}

th{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--m-muted);text-align:left;padding:10px 12px;white-space:nowrap}

td{padding:10px 12px;border-top:1px solid #f4f4f5;vertical-align:middle}

.pill{font-size:11px;padding:4px 10px;border-radius:999px;font-weight:700;border:1px solid var(--m-line)}

.pill-ekskul{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}

.pill-event{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}

.skeleton-wrap{padding:12px}

.skel{height:14px;background:#f4f4f5;border-radius:6px;animation:pulse 1.2s infinite;margin:8px 0}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

.empty{display:grid;place-items:center;padding:32px;color:var(--m-muted);font-size:13px}

.kat-paging{margin-top:0;padding:12px 16px;border-top:1px solid var(--m-line);display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fafafa}

.kat-paging.inside{margin-top:0}

.kat-count{font-size:11px;color:var(--m-muted)}

.kat-paging-btns{display:flex;gap:8px;align-items:center}

.kat-page-btn{padding:6px 12px;border-radius:8px;border:1px solid var(--m-line);background:#fff;font-size:13px;min-width:32px;min-height:32px;color:var(--m-ink);cursor:pointer}

.kat-page-btn:disabled{opacity:.4;cursor:not-allowed}

.kat-page-num{padding:6px 10px;border:1px solid var(--m-line);background:#fff;border-radius:8px;font-size:12px;color:var(--m-ink)}

.kat-toast{position:fixed;bottom:16px;right:16px;padding:12px 16px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:60;font-size:12.5px;border:1px solid var(--m-line)}

.kat-toast.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}

.kat-toast.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}

.preview-overlay{position:fixed;inset:0;background:rgba(47,62,70,.55);backdrop-filter:blur(6px);display:grid;place-items:center;z-index:80;padding:16px}

.preview-modal{background:#fff;border:1px solid var(--m-line);border-radius:16px;padding:14px;max-width:980px;width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:auto}
.preview-modal-body{overflow:auto;min-height:0}
.pdf-frame{background:#fff;border-radius:12px}

.preview-head{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding-bottom:10px;border-bottom:1px solid var(--m-line);margin-bottom:10px}

.muted{color:var(--m-muted)}

.spin{width:14px;height:14px;border:2px solid #e4e4e7;border-top-color:var(--m-ink);border-radius:999px;display:inline-block;animation:sp .6s linear infinite}

.spin-white{border-color:rgba(255,255,255,.3);border-top-color:#fff}

@keyframes sp{to{transform:rotate(360deg)}}

.preview-cert-wrap{position:relative}
.cert-preview-iframe{border-radius:14px}
/* editor mode */
.cert-editor-grid{display:block}
.cert-editor-grid.is-edit{display:grid;grid-template-columns: 1fr 300px;gap:14px;align-items:start}
@media(max-width:1100px){ .cert-editor-grid.is-edit{grid-template-columns:1fr} }
.preview-cert-wrap.edit-stage{cursor:crosshair}
.ov-field.selected{outline:2px dashed #4A7875;outline-offset:2px;background: rgba(74,120,117,.06);cursor:move}
.ov-field.locked{opacity:.6;cursor:not-allowed;outline-color:#94a3b8}
.ov-field.dragging{opacity:.85;z-index:5}
.ov-field{min-height:8px; min-width:10px}
.rz-handle{position:absolute;width:10px;height:10px;background:#fff;border:1.5px solid #4A7875;border-radius:2px;z-index:3}
.rz-handle.nw{left:-5px;top:-5px;cursor:nw-resize}
.rz-handle.ne{right:-5px;top:-5px;cursor:ne-resize}
.rz-handle.sw{left:-5px;bottom:-5px;cursor:sw-resize}
.rz-handle.se{right:-5px;bottom:-5px;cursor:se-resize}
.rz-handle.n{left:50%;top:-5px;transform:translateX(-50%);cursor:n-resize}
.rz-handle.s{left:50%;bottom:-5px;transform:translateY(-50%);cursor:s-resize}
.rz-handle.e{right:-5px;top:50%;transform:translateY(-50%);cursor:e-resize}
.rz-handle.w{left:-5px;top:50%;transform:translateY(-50%);cursor:w-resize}
.rz-badge{position:absolute;left:50%;top:-18px;transform:translateX(-50%);background:#2F3E46;color:#fff;font-size:8px;font-weight:800;letter-spacing:.06em;padding:2px 6px;border-radius:999px;white-space:nowrap;pointer-events:none}
.cert-props{background:#fff;border:1px solid var(--m-line);border-radius:12px;padding:12px;position:sticky;top:12px}
.props-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.prop{display:flex;flex-direction:column;gap:4px}
.prop span{font-size:9px;font-weight:700;letter-spacing:.06em;color:#6B7C85}
.cert-bg-grid{display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start}
.cert-bg-preview{width:280px; aspect-ratio:297/210; border:1px dashed var(--m-line); border-radius:12px; overflow:hidden; background:#fafafa; display:grid; place-items:center}
.cert-bg-actions{flex:1;min-width:260px}
.cert-live-preview{margin-top:4px}
.preview-tabs{border-bottom:1px solid var(--m-line); padding-bottom:8px}
@media(max-width:900px){ .cert-bg-grid{grid-template-columns:1fr} .cert-bg-preview{width:100%} }
@media(max-width:640px){

 .kat-page{padding:16px 16px 24px}

 .kat-head{flex-direction:column;align-items:flex-start}

 .cert-preview{width:100%}

 .table-head{flex-direction:column;align-items:stretch;gap:8px}
 .table-head-l{width:100%}
 .table-filters{flex-direction:column;align-items:stretch;width:100%}
 .table-filter-select,.table-filter-search{flex:none;width:100%;max-width:none;min-width:0}
 .table-filter-reset{align-self:flex-end}
 .kat-search{flex:none;width:100%}

 /* daftar sertifikat: hilangkan scroll samping -> card stack tanpa overflow-x */

 .table-wrap{overflow:visible}

 table{width:100%}

 thead{display:none}

 tbody{display:grid;gap:10px;padding:10px;background:#f8fafb}

 tbody tr{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;background:#fff;border:1px solid var(--m-line);border-radius:14px;padding:12px;box-shadow:0 1px 3px rgba(0,0,0,.06)}

 tbody td{padding:0;border:none;vertical-align:top;white-space:normal;word-break:break-word;overflow-wrap:anywhere}

 tbody td:nth-child(1){grid-column:1/-1;font-size:12px;letter-spacing:.02em}

 tbody td:nth-child(2){grid-column:1/-1}

   tbody td:nth-child(3),tbody td:nth-child(4),tbody td:nth-child(5),tbody td:nth-child(6),tbody td:nth-child(7),tbody td:nth-child(8),tbody td:nth-child(9){font-size:11.5px}

   tbody td:nth-child(4)::before{content:'Kelas: ';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:4px}
   tbody td:nth-child(5)::before{content:'Label: ';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:4px}
   tbody td:nth-child(6)::before{content:'Tipe: ';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:4px}

   tbody td:nth-child(7)::before{content:'Target: ';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:4px}

   tbody td:nth-child(8){display:flex;align-items:center;gap:6px;flex-wrap:wrap}

   tbody td:nth-child(8)::before{content:'Hash:';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:2px}

   tbody td:nth-child(9)::before{content:'Terbit: ';font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--m-muted);margin-right:4px}

 tbody td:last-child{grid-column:1/-1;display:flex;gap:6px;flex-wrap:nowrap;justify-content:stretch;margin-top:4px}

 tbody td:last-child .kat-mini{flex:1;justify-content:center;padding:8px 10px;font-size:12px}

}

</style>

