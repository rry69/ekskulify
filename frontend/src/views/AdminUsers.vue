<template>



<div class="kat-page">



<div class="kat-inner">



 <!-- head: flat tool header ( Katalog/Events/Kalender) -->



 <div class="kat-head">



 <div class="kat-head-l">



 <h1 class="kat-title" :title="headSub">Kelola Users</h1>



 </div>



 <div class="kat-head-r">



 <button class="kat-link" @click="showLogs=true; loadAudit()" aria-label="Log aktivitas">Log</button>



 <button class="kat-link" @click="doExport" aria-label="Export CSV">Export CSV</button>






 <button class="kat-link" @click="showBulkKelas=true" aria-label="Bulk Kelas">Bulk Kelas</button>



 <button class="kat-link strong" @click="openCreate" aria-label="Tambah user">+ Tambah User</button>



 </div>



 </div>



 <!-- Stats 4 cards - Mindora flat stat strip -->



 <div class="kat-stats" aria-label="Statistik users">



 <div class="kat-stat">



 <div class="kat-stat-label mono">TOTAL</div>



 <div class="kat-stat-value">{{ stats.total }}</div>



 </div>



 <div class="kat-stat">



 <div class="kat-stat-label mono">SISWA</div>



 <div class="kat-stat-value">{{ stats.siswa }}</div>



 </div>



 <div class="kat-stat">



 <div class="kat-stat-label mono">PEMBINA</div>



 <div class="kat-stat-value">{{ stats.pembina }}</div>



 </div>



 <div class="kat-stat">



 <div class="kat-stat-label mono">ADMIN - KEPSEK</div>



 <div class="kat-stat-value">{{ stats.admin_kepsek }}</div>



 </div>



 </div>







 <!-- Activity sparkline 14 hari - mini chart -->



 <div v-if="activity.labels.length" class="kat-card" style="padding:12px 16px;margin-bottom:12px">



 <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">



 <div class="mono" style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#6B7C85">AKTIVITAS {{ activityDays }} HARI</div>



 <div style="display:flex;gap:6px">



 <button class="kat-chip small" :class="{active: activityDays==='7'}" @click="activityDays='7'; fetchActivity()">7h</button>



 <button class="kat-chip small" :class="{active: activityDays==='14'}" @click="activityDays='14'; fetchActivity()">14h</button>



 <button class="kat-chip small" :class="{active: activityDays==='30'}" @click="activityDays='30'; fetchActivity()">30h</button>



 </div>



 </div>



 <div style="height:72px;position:relative"><canvas ref="activityCanvas" aria-label="Sparkline aktivitas"></canvas></div>



 <div v-if="!activity.labels.length" class="muted mono" style="font-size:11px;padding:8px 0">Belum ada aktivitas</div>



 </div>



 <div v-else-if="activityLoading" class="kat-card" style="padding:12px 16px;margin-bottom:12px"><div class="skeleton" style="height:72px"></div></div>







 <!-- Tabs Aktif / Arsip - chip style -->



 <div class="kat-tabs" role="tablist" aria-label="Tab arsip">



 <button class="kat-chip" :class="{active: !showArchived}" role="tab" :aria-selected="!showArchived" @click="switchTab(false)" :title="total + ' aktif'">Aktif</button>



 <button class="kat-chip" :class="{active: showArchived}" role="tab" :aria-selected="showArchived" @click="switchTab(true)" :title="archivedTotal + ' arsip'">Arsip</button>



 </div>







 <!-- Toolbar: sticky tool strip ( Katalog) -->



 <div class="kat-toolbar" role="search" aria-label="Filter users">



 <div class="kat-search">



 <span class="kat-search-icon" aria-hidden="true"></span>



 <input id="q-users" v-model="q" type="search" placeholder="Cari nama atau email..." aria-label="Cari nama atau email" class="kat-input" />



 <button v-if="q" class="kat-clear" @click="q=''" aria-label="Hapus pencarian">x</button>



 </div>



 <div class="kat-filters">



 <select v-model="role" class="kat-select" aria-label="Filter role">



 <option value="all">Semua role</option>



 <option value="admin">Admin</option>



 <option value="pembina">Pembina</option>



 <option value="siswa">Siswa</option>



 <option value="kepsek">Kepsek</option>



 </select>



 <select v-model="kelasFilter" class="kat-select" aria-label="Filter kelas" :disabled="role!=='siswa' || showArchived" :title="role!=='siswa' ? 'Pilih role Siswa dulu' : (showArchived ? 'Tidak tersedia di Arsip' : 'Filter kelas siswa')">



 <option value="all">Semua kelas</option>



 <option v-for="k in kelasList" :key="k" :value="k">{{ k }}</option>



 </select>



 <select v-model="statusFilter" class="kat-select" aria-label="Filter status">



 <option value="all">Semua status</option>



 <option value="aktif">Aktif</option>



 <option value="suspended">Suspended</option>



 <option value="nonaktif">Nonaktif</option>



 </select>



 <select v-model="sort" class="kat-select" aria-label="Sort">



 <option value="newest">Terbaru</option>



 <option value="oldest">Terlama</option>



 <option value="nama">Nama A-Z</option>



 <option value="email">Email A-Z</option>



 <option value="role">Role</option>



 <option value="status">Status</option>



 </select>



 <button class="kat-reset" @click="resetFilters" aria-label="Reset filter">Reset</button>



 <select v-model.number="limit" class="kat-select" aria-label="Limit per page" style="min-width:92px">



 <option :value="10">10 / page</option>



 <option :value="20">20 / page</option>



 <option :value="50">50 / page</option>



 <option :value="100">100 / page</option>



 </select>



 </div>



 </div>







 <!-- Bulk bar -->



  <div v-if="selectedIds.length" class="kat-bulk" role="toolbar" aria-label="Bulk actions">



  <span class="kat-bulk-count mono">{{ selectedIds.length }} dipilih:</span>

  <template v-if="!showArchived">

  <button class="kat-mini neutral" @click="bulkAction('suspend')">Suspend</button>



  <button class="kat-mini neutral" @click="bulkAction('activate')">Aktifkan</button>



  <select v-model="bulkRole" class="kat-select" style="padding:6px 8px;font-size:12px;min-height:28px">



  <option value="">Ganti role...</option>



  <option value="siswa">-> Siswa</option>



  <option value="pembina">-> Pembina</option>



  <option value="admin">-> Admin</option>



  <option value="kepsek">-> Kepsek</option>



  </select>



  <button class="kat-mini neutral" :disabled="!bulkRole" @click="bulkAction('change_role')">Ganti Role</button>



  <button class="kat-mini danger" @click="bulkAction('delete')">Hapus</button>



  <button class="kat-mini neutral" @click="doBulkExport">Export</button>

  </template>

  <template v-else>

  <button class="kat-mini neutral" @click="archiveBulkAction('restore')">Restore</button>

  <button class="kat-mini danger" @click="archiveBulkAction('purge')">Hapus Permanen</button>

  </template>

  <button class="kat-mini ghost" @click="selectedIds=[]">Batal</button>



 </div>







 <!-- Table card - DESKTOP: table with isolated horizontal scroll; MOBILE: card list (no bleed) -->



 <div class="kat-card kat-table-card">



 <div class="table-wrap desktop-table-wrap">



 <table class="users-table">



 <thead>



 <tr>



  <th scope="col" style="width:36px"><input type="checkbox" :checked="allChecked" @change="toggleAll" aria-label="Pilih semua" /></th>



 <th scope="col">USER</th>



 <th scope="col">ROLE</th>



 <th scope="col" class="hide-sm">KELAS</th>



 <th scope="col" class="hide-sm">STATUS</th>



 <th scope="col" class="hide-md">TERDAFTAR</th>



 <th scope="col" style="text-align:right">AKSI</th>



 </tr>



 </thead>



 <tbody v-if="loading" aria-busy="true" aria-label="Memuat users">



 <tr v-for="i in 5" :key="i" class="skeleton-row">



 <td><div class="skeleton" style="width:16px;height:16px"></div></td>



 <td><div class="skeleton" style="width:160px;height:14px"></div><div class="skeleton" style="width:120px;height:10px;margin-top:6px"></div></td>



 <td><div class="skeleton" style="width:60px;height:20px;border-radius:999px"></div></td>



 <td class="hide-sm"><div class="skeleton" style="width:50px;height:20px;border-radius:999px"></div></td>



 <td class="hide-sm"><div class="skeleton" style="width:50px;height:20px;border-radius:999px"></div></td>



 <td class="hide-md"><div class="skeleton" style="width:80px;height:12px"></div></td>



 <td style="text-align:right"><div class="skeleton" style="width:160px;height:28px;margin-left:auto;border-radius:8px"></div></td>



 </tr>



 </tbody>



 <tbody v-else-if="!list.length">



 <tr><td colspan="7" class="empty-cell mono">{{ showArchived ? 'Arsip kosong' : 'Tidak ada user' }}</td></tr>



 </tbody>



 <tbody v-else>



 <tr v-for="u in list" :key="u.id" class="row-hover">



  <td><input type="checkbox" :value="u.id" v-model="selectedIds" :aria-label="'Pilih '+u.nama" /></td>



 <td>



 <div class="user-cell" @click="openDetail(u)" style="cursor:pointer" :title="'Lihat detail '+u.nama">



 <div class="avatar" aria-hidden="true">{{ initials(u.nama) }}</div>



 <div>



 <div class="user-name" style="display:flex;align-items:center;gap:6px">{{ u.nama }} <span style="font-size:10px;color:#6B7C85" aria-hidden="true"></span></div>



 <div class="user-email mono">{{ u.email }}</div>



 </div>



 </div>



 </td>



 <td><span class="badge-role" :class="'role-'+u.role">{{ u.role }}</span></td>



 <td class="hide-sm"><span v-if="u.role==='siswa' && u.kelas" class="badge-kelas mono">{{ u.kelas }}</span><span v-else class="muted mono" style="font-size:11px">-</span></td>



 <td class="hide-sm"><span class="badge-status" :class="'st-'+(u.status||'aktif')">{{ u.status || 'aktif' }}</span></td>



 <td class="hide-md mono" style="font-size:11px">



 <div class="muted">{{ formatDate(u.created_at) }}</div>



 <div :class="loginCls(u.last_login_at)" :title="u.last_login_at ? 'Last login: '+u.last_login_at : 'Belum pernah login'" style="display:flex;align-items:center;gap:4px;margin-top:2px">



 <span class="login-dot" :style="{background: loginDot(u.last_login_at)}"></span>



 <span>{{ loginLabel(u.last_login_at) }}</span>



 </div>



 </td>



 <td>



 <div class="actions" v-if="!showArchived">



 <button class="kat-mini neutral" @click="openDetail(u)" :aria-label="'Detail '+u.nama">Detail</button>



 <button class="kat-mini neutral" @click="openEdit(u)" :aria-label="'Edit '+u.nama">Edit</button>



 <button class="kat-mini" :class="u.status==='aktif'?'warn':''" @click="toggleSuspend(u)" :aria-label="u.status==='aktif'?'Suspend '+u.nama:'Aktifkan '+u.nama">{{ u.status==='aktif' ? 'Suspend' : 'Aktifkan' }}</button>



 <button class="kat-mini danger" @click="confirmDelete(u)" :aria-label="'Hapus '+u.nama">Hapus</button>



 <button class="kat-mini neutral hide-sm" @click="openReset(u)" :aria-label="'Reset password '+u.nama">Reset</button>



 </div>



 <div class="actions" v-else>



 <button class="kat-mini neutral" @click="doRestore(u)" :aria-label="'Restore '+u.nama">Restore</button>



 <button class="kat-mini danger" @click="askPurge(u)" :aria-label="'Hapus permanen '+u.nama">Hapus Permanen</button>



 </div>



 </td>



 </tr>



 </tbody>



 </table>



 </div>



 <!-- MOBILE: card list - no horizontal scroll, rapih -->



 <div class="mobile-cards" aria-label="Daftar users mobile">



 <template v-if="loading">



 <div v-for="i in 4" :key="i" class="mcard skeleton-card"><div class="skeleton" style="height:78px;border-radius:12px"></div></div>



 </template>



 <div v-else-if="!list.length" class="empty-cell mono" style="padding:28px 16px">{{ showArchived ? 'Arsip kosong' : 'Tidak ada user' }}</div>



 <template v-else>



 <div v-for="u in list" :key="u.id" class="mcard">



 <div class="mcard-top">



  <label class="mcard-check"><input type="checkbox" :value="u.id" v-model="selectedIds" :aria-label="'Pilih '+u.nama" /></label>



 <div class="avatar mcard-avatar" aria-hidden="true">{{ initials(u.nama) }}</div>



 <div class="mcard-main" @click="openDetail(u)" style="cursor:pointer">



 <div class="mcard-name">{{ u.nama }}</div>



 <div class="mcard-email mono">{{ u.email }}</div>



 </div>



 <button class="mcard-go" @click="openDetail(u)" aria-label="Detail">></button>



 </div>



 <div class="mcard-badges">



 <span class="badge-role" :class="'role-'+u.role">{{ u.role }}</span>



 <span v-if="u.role==='siswa' && u.kelas" class="badge-kelas mono">{{ u.kelas }}</span>



 <span class="badge-status" :class="'st-'+(u.status||'aktif')">{{ u.status || 'aktif' }}</span>



 </div>



 <div class="mcard-meta mono">



 <span>Terdaftar {{ formatDate(u.created_at) }}</span>



 <span class="mcard-dot"> - </span>



 <span :class="loginCls(u.last_login_at)" style="display:inline-flex;align-items:center;gap:4px"><span class="login-dot" :style="{background: loginDot(u.last_login_at)}"></span>{{ loginLabel(u.last_login_at) }}</span>



 </div>



 <div class="mcard-actions" v-if="!showArchived">



 <button class="kat-mini neutral" @click="openDetail(u)">Detail</button>



 <button class="kat-mini neutral" @click="openEdit(u)">Edit</button>



 <button class="kat-mini" :class="u.status==='aktif'?'warn':''" @click="toggleSuspend(u)">{{ u.status==='aktif' ? 'Suspend' : 'Aktifkan' }}</button>



 <button class="kat-mini danger" @click="confirmDelete(u)">Hapus</button>



 </div>



 <div class="mcard-actions" v-else>



 <button class="kat-mini neutral" @click="doRestore(u)">Restore</button>



 <button class="kat-mini danger" @click="askPurge(u)">Hapus Permanen</button>



 </div>



 </div>



 </template>



 </div>



 <div class="kat-paging inside">



 <span class="kat-count mono">{{ paginationInfo }}</span>



 <div class="kat-paging-btns">



 <button class="kat-page-btn" :disabled="page<=1" @click="goPage(page-1)" aria-label="Previous page">Prev</button>



 <template v-for="p in pageNumbers" :key="p">



 <span v-if="p==='...'" class="page-ellipsis mono">...</span>



 <button v-else class="kat-page-btn" :class="{active: p===page}" @click="goPage(p)">{{ p }}</button>



 </template>



 <button class="kat-page-btn" :disabled="page>=pages" @click="goPage(page+1)" aria-label="Next page">Next</button>



 <span class="jump-wrap mono">Jump <input v-model="jumpInput" type="number" :min="1" :max="pages" class="jump-input" aria-label="Jump to page" @keydown.enter="goJump" /> <button class="kat-page-btn" @click="goJump">Go</button></span>



 </div>



 </div>



 </div>














 <!-- Toast / error -->



 <div v-if="toast" class="kat-toast" :class="toastOk?'ok':'err'" role="status" aria-live="polite">{{ toast }}</div>



 <div v-if="error" class="kat-alert err" role="alert">{{ error }}</div>







 <!-- Modal Tambah/Edit -->



 <Teleport to="body">



 <div v-if="showModal" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="closeModal"></div>



 <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">



 <div class="w-full max-w-[560px] max-h-[90vh] overflow-hidden rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border:1px solid #E0E5E3">



 <div class="shrink-0 bg-white px-6 py-4 flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">



 <div>



 <h2 class="text-[18px] font-bold" style="color:#2F3E46">{{ editing ? 'Edit User' : 'Tambah User' }}</h2>



 <p class="mono text-[11px] mt-0.5" style="color:#6B7C85">{{ editing ? 'Update data pengguna' : 'Buat akun baru' }}</p>



 </div>



 <button type="button" @click="closeModal" class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50 transition" style="border:1px solid #E0E5E3" aria-label="Tutup">x</button>



 </div>



 <div class="p-6 overflow-auto flex-1 min-h-0">



 <div class="field">



 <label class="field-label mono">NAMA *</label>



 <input v-model="form.nama" placeholder="Nama lengkap" class="kat-field-input" aria-label="Nama" />



 <div v-if="form.nama && form.nama.trim().length<2" class="field-error">Minimal 2 karakter</div>



 </div>



 <div class="field">



 <label class="field-label mono">EMAIL *</label>



 <input v-model="form.email" placeholder="nama@sekolah.test" class="kat-field-input" type="email" aria-label="Email" />



 <div v-if="form.email && !isEmailValid" class="field-error">Email tidak valid</div>



 </div>



 <div class="field">



 <label class="field-label mono">PASSWORD {{ editing ? '' : '*' }}</label>



 <div class="pass-wrap" style="display:flex;gap:6px">



 <div style="position:relative;flex:1">



 <input :type="showPass?'text':'password'" v-model="form.password" :placeholder="editing?'Kosongkan jika tidak ganti':'Min 8 karakter'" class="kat-field-input pass-input" aria-label="Password" @input="onPassInput" style="padding-right:40px" />



 <button type="button" class="btn-eye" @click="showPass=!showPass" :aria-label="showPass?'Sembunyikan password':'Tampilkan password'"></button>



 </div>



 <button type="button" class="kat-mini neutral" @click="genPassword()" title="Generate password acak" aria-label="Generate password" style="white-space:nowrap;align-self:stretch"> Generate</button>



 </div>



 <div class="strength-track"><div class="strength-bar" :style="{width: strength.w+'%'}" :class="'s-'+strength.score"></div></div>



 <div class="field-hint mono">Kekuatan: {{ strength.label }}</div>



 <div v-if="form.password && form.password.length>0 && form.password.length<8" class="field-error">Minimal 8 karakter</div>



 </div>



 <div class="grid-2">



 <div class="field">



 <label class="field-label mono">ROLE *</label>



 <select v-model="form.role" class="kat-field-input kat-select-field" aria-label="Role">



 <option value="siswa">Siswa</option>



 <option value="pembina">Pembina</option>



 <option value="admin">Admin</option>



 <option value="kepsek">Kepsek</option>



 </select>



 </div>



 <div class="field" v-if="form.role==='siswa'">



 <label class="field-label mono">KELAS</label>



 <input v-model="form.kelas" placeholder="10A" class="kat-field-input" aria-label="Kelas" />



 </div>



 <div class="field" v-else-if="form.role==='pembina' || form.role==='kepsek'">



 <label class="field-label mono">NIP</label>



 <input v-model="form.nip" placeholder="NIP (opsional)" class="kat-field-input" aria-label="NIP" />



 </div>



 <div class="field" v-else>



 <label class="field-label mono">KELAS / NIP</label>



 <input :value="'-'" disabled class="kat-field-input" style="background:#F1F5F4;color:#6B7C85" aria-label="Kelas NIP tidak perlu untuk admin" />



 </div>



 </div>



 <!-- Riwayat tab in edit -->



 <div v-if="editing" class="history-tabs">



 <button class="kat-chip small" :class="{active: editTab==='form'}" @click="editTab='form'">Form</button>



 <button class="kat-chip small" :class="{active: editTab==='logs'}" @click="loadUserLogs(editing)">Riwayat</button>



 </div>



 <div v-if="editing && editTab==='logs'" class="logs-wrap">



 <div v-if="userLogs.length===0" class="muted mono" style="font-size:12px;padding:8px 0">Belum ada riwayat</div>



 <div v-for="l in userLogs" :key="l.id" class="log-line"><span class="mono" style="font-size:11px">{{ l.created_at }}</span> - {{ l.actor_nama||('user '+l.user_id) }} {{ l.action }} <span class="muted">{{ l.detail }}</span> <span class="mono muted">IP {{ l.ip||'-' }}</span></div>



 </div>



 <div v-if="formErr" class="field-error" style="margin-top:8px">{{ formErr }}</div>



 </div>



 <div v-if="editTab==='form'" class="shrink-0 bg-white px-6 py-4 flex justify-end gap-2" style="border-top:1px solid #E0E5E3">



 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50 transition" style="border-color:#E0E5E3;color:#2F3E46" @click="closeModal">Batal</button>



 <button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold hover:opacity-90 transition disabled:opacity-40" style="background:#4A7875" @click="save" :disabled="isSaveDisabled" :aria-disabled="isSaveDisabled">Simpan</button>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Confirm Hapus -->



 <Teleport to="body">



 <div v-if="showConfirm" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showConfirm=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4">



 <div class="w-full max-w-[420px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">



 <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">



 <h2 class="text-[16px] font-bold" style="color:#2F3E46">Hapus User?</h2>



 <button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showConfirm=false" aria-label="Tutup">x</button>



 </div>



 <div class="p-6">



 <p class="mono text-[12.5px]" style="color:#6B7C85">Hapus {{ confirmTarget?.nama }}? soft-delete + audit log.</p>



 <div class="flex gap-2 justify-end mt-4">



 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showConfirm=false">Batal</button>



 <button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#dc2626" @click="doDelete">Hapus</button>



 </div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Purge confirm -->



 <Teleport to="body">



 <div v-if="showPurge" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showPurge=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4">



 <div class="w-full max-w-[420px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">



 <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3"><h2 class="text-[16px] font-bold" style="color:#2F3E46">Hapus Permanen?</h2><button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showPurge=false">x</button></div>



 <div class="p-6">



 <p class="mono text-[12.5px]" style="color:#6B7C85">Ketik nama <b style="color:#2F3E46">{{ purgeTarget?.nama }}</b> untuk konfirmasi:</p>



 <input v-model="purgeConfirm" class="kat-field-input mt-3" :placeholder="purgeTarget?.nama" />



 <div class="flex gap-2 justify-end mt-4"><button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showPurge=false">Batal</button><button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold disabled:opacity-40" style="background:#dc2626" :disabled="purgeConfirm!==purgeTarget?.nama" @click="doPurge">Hapus Permanen</button></div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Bulk confirm -->



 <Teleport to="body">



 <div v-if="showBulkConfirm" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showBulkConfirm=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4">



 <div class="w-full max-w-[420px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">



 <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3"><h2 class="text-[16px] font-bold" style="color:#2F3E46">Bulk {{ bulkActionType }}</h2><button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showBulkConfirm=false">x</button></div>



 <div class="p-6">



 <p class="mono text-[12.5px]" style="color:#6B7C85">Yakin {{ bulkActionType }} {{ selectedIds.length }} user? soft-delete + audit.</p>



 <div class="flex gap-2 justify-end mt-4"><button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showBulkConfirm=false">Batal</button><button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#dc2626" @click="doBulk">Ya, Lanjutkan</button></div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>

  <!-- Archive bulk confirm -->
  <Teleport to="body">
  <div v-if="showArchiveBulkConfirm" class="fixed inset-0 z-50">
  <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showArchiveBulkConfirm=false"></div>
  <div class="absolute inset-0 grid place-items-center p-4">
  <div class="w-full max-w-[420px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">
  <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3"><h2 class="text-[16px] font-bold" style="color:#2F3E46">Bulk {{ archiveBulkActionType }}</h2><button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showArchiveBulkConfirm=false">x</button></div>
  <div class="p-6">
  <p class="mono text-[12.5px]" style="color:#6B7C85">Yakin {{ archiveBulkActionType }} {{ selectedIds.length }} user?</p>
  <div class="flex gap-2 justify-end mt-4"><button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showArchiveBulkConfirm=false">Batal</button><button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#dc2626" @click="doArchiveBulk">Ya, Lanjutkan</button></div>
  </div>
  </div>
  </div>
  </div>
  </Teleport>







  <!-- Reset Password -->



 <Teleport to="body">



 <div v-if="showReset" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showReset=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4">



 <div class="w-full max-w-[420px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">



 <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">



 <h2 class="text-[16px] font-bold" style="color:#2F3E46">Reset Password - {{ resetTarget?.nama }}</h2>



 <button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showReset=false" aria-label="Tutup">x</button>



 </div>



 <div class="p-6">



 <div class="field">



 <label class="field-label mono">PASSWORD BARU *</label>



 <div class="pass-wrap" style="display:flex;gap:6px">



 <div style="position:relative;flex:1">



 <input :type="showResetPass?'text':'password'" v-model="resetPass" placeholder="Min 8 karakter" class="kat-field-input pass-input" aria-label="Password baru" style="padding-right:40px" />



 <button type="button" class="btn-eye" @click="showResetPass=!showResetPass" aria-label="Toggle password"></button>



 </div>



   <button type="button" class="kat-mini neutral" @click="genResetPass()" aria-label="Generate password">Generate</button>



 </div>



  <div v-if="resetPass" class="gen-pass-box" style="margin-top:8px;display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #E0E5E3;background:#F8FAF9;border-radius:10px">
    <code class="mono" style="flex:1;font-size:13px;letter-spacing:.04em;word-break:break-all;color:#2F3E46">{{ resetPass }}</code>
    <button type="button" class="kat-mini neutral" @click="copyResetPass()" :class="{'!bg-[#4A7875] !text-white': resetCopied}" style="white-space:nowrap;flex-shrink:0">{{ resetCopied ? 'v Tercopy' : 'Copy' }}</button>
  </div>
  <div v-if="resetPass && resetPass.length<8" class="field-error">Minimal 8 karakter</div>



 </div>



 <div class="flex gap-2 justify-end mt-4">



 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showReset=false">Batal</button>



 <button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold disabled:opacity-40" style="background:#4A7875" @click="doReset" :disabled="resetPass.length<8">Reset</button>



 </div>

 <div class="mt-4 pt-4" style="border-top:1px dashed #E0E5E3">
 <p class="mono" style="font-size:11px;color:#6B7C85;letter-spacing:.06em">RESET SESUAI METODE AKTIF (PENGATURAN)</p>
 <p class="caption" style="font-size:12px;color:#57534e;margin-top:4px;line-height:1.6">Sistem mengikuti metode di Pengaturan — Password Sementara atau Link Reset Sekali Pakai.</p>
 <div v-if="forgotErr" class="field-error" role="alert" style="margin-top:8px">{{ forgotErr }}</div>
 <div v-if="forgotResult" class="gen-pass-box" style="margin-top:8px;display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #E0E5E3;background:#F8FAF9;border-radius:10px">
 <code class="mono" style="flex:1;font-size:12px;letter-spacing:.02em;word-break:break-all;color:#2F3E46;white-space:pre-wrap">{{ forgotResultText() }}</code>
 <button type="button" class="kat-mini neutral" @click="copyForgotResult()" :class="{'!bg-[#4A7875] !text-white': forgotCopied}" style="white-space:nowrap;flex-shrink:0">{{ forgotCopied ? 'v Tercopy' : 'Copy' }}</button>
 </div>
 <div v-if="forgotResult?.method==='link'" class="caption" style="font-size:11px;color:#6B7C85;margin-top:6px">Kadaluarsa: {{ forgotResult.expires_at }} (30 menit, sekali pakai)</div>
 <div class="flex gap-2 justify-end mt-3">
 <button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50 disabled:opacity-40" style="border:1px solid #E0E5E3" @click="doForgotReset" :disabled="forgotLoading">{{ forgotLoading ? 'Memproses...' : 'Reset Sesuai Metode' }}</button>
 </div>
 </div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Audit drawer -->



 <Teleport to="body">



 <div v-if="showLogs" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showLogs=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">



 <div class="w-full max-w-[720px] max-h-[80vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border:1px solid #E0E5E3">



 <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3"><h2 class="text-[16px] font-bold" style="color:#2F3E46">Log Aktivitas</h2><button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showLogs=false">x</button></div>



 <div class="p-6">



 <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">



 <input v-model="logQ" placeholder="Cari detail / aktor" class="kat-field-input" style="flex:1;min-width:160px" />



 <select v-model="logAction" class="kat-select"><option value="all">Semua aksi</option><option value="create">create</option><option value="update">update</option><option value="delete">delete</option><option value="restore">restore</option><option value="purge">purge</option><option value="reset_password">reset_password</option><option value="suspend">suspend</option><option value="bulk">bulk</option><option value="export">export</option></select>



 <button class="kat-link" @click="loadAudit">Cari</button>



 </div>



 <div v-for="l in auditRows" :key="l.id" class="log-line"><span class="mono" style="font-size:11px">{{ l.created_at }}</span> - <b>{{ l.actor_nama||l.user_id }}</b> {{ l.action }} {{ l.target_type }}#{{ l.target_id }} <span class="muted">{{ l.detail }}</span> <span class="mono muted">IP {{ l.ip||'-' }}</span></div>



 <div v-if="!auditRows.length" class="muted mono" style="font-size:12px;padding:12px 0">Tidak ada log</div>



 <div class="kat-paging inside" style="margin-top:12px"><button class="kat-page-btn" :disabled="logPage<=1" @click="logPage--; loadAudit()">Prev</button><span class="mono" style="font-size:12px;padding:6px 8px;color:#6B7C85">Hal {{ logPage }}</span><button class="kat-page-btn" @click="logPage++; loadAudit()">Next</button></div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Detail Drawer -->



 <Teleport to="body">



 <div v-if="showDetail" class="fixed inset-0 z-40">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showDetail=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4 overflow-auto">



 <div class="w-full max-w-[720px] max-h-[85vh] overflow-auto rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] flex flex-col" style="border:1px solid #E0E5E3">



 <div class="sticky top-0 bg-white px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">



 <div>



 <h2 class="text-[16px] font-bold" style="color:#2F3E46">Detail User - {{ detailData?.user?.nama || '-' }}</h2>



 <p class="mono text-[11px]" style="color:#6B7C85">{{ detailData?.user?.email || '' }} - {{ detailData?.user?.role || '' }} <span v-if="detailData?.user?.kelas"> - {{ detailData.user.kelas }}</span> <span v-if="detailData?.user?.nip"> - NIP {{ detailData.user.nip }}</span></p>



 </div>



 <button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showDetail=false" aria-label="Tutup">x</button>



 </div>



 <div class="p-6">



 <div v-if="detailLoading" class="skeleton" style="height:120px"></div>



 <template v-else-if="detailData">



 <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">



 <div class="kat-stat" style="padding:10px 12px"><div class="kat-stat-label mono">STATUS</div><div class="kat-stat-value" style="font-size:14px">{{ detailData.user.status }}</div><div class="kat-stat-desc mono">terdaftar {{ formatDate(detailData.user.created_at) }}</div></div>



 <div class="kat-stat" style="padding:10px 12px"><div class="kat-stat-label mono">LAST LOGIN</div><div class="kat-stat-value" style="font-size:13px;display:flex;align-items:center;gap:6px"><span class="login-dot" :style="{background: loginDot(detailData.user.last_login_at)}"></span>{{ loginLabel(detailData.user.last_login_at) }}</div><div class="kat-stat-desc mono">{{ detailData.user.last_login_at || 'belum pernah' }}</div></div>



 <div class="kat-stat" style="padding:10px 12px"><div class="kat-stat-label mono">KEHADIRAN 30H</div><div class="kat-stat-value" style="font-size:14px">{{ detailData.att.hadir }}/{{ detailData.att.total }}</div><div class="kat-stat-desc mono">izin {{ detailData.att.izin }} - alpa {{ detailData.att.alpa }}</div></div>



 </div>



 <div style="margin-bottom:14px">



 <div class="field-label mono">EKSKUL TERDAFTAR ({{ detailData.ekskul.length }})</div>



 <div v-if="!detailData.ekskul.length" class="muted mono" style="font-size:12px;padding:6px 0">Belum ada ekskul</div>



 <div v-for="e in detailData.ekskul" :key="e.id" class="log-line" style="display:flex;justify-content:space-between;gap:8px"><span><b>{{ e.nama }}</b> <span class="badge-role" :class="'role-'+e.status" style="font-size:10px;padding:2px 6px">{{ e.status }}</span> <span class="mono muted" style="font-size:11px"> - {{ e.reg_status }}</span></span><span class="mono muted" style="font-size:11px">{{ formatDate(e.created_at) }}</span></div>



 </div>



 <div style="margin-bottom:14px">



 <div class="field-label mono">EVENT PESERTA ({{ detailData.events.length }})</div>



 <div v-if="!detailData.events.length" class="muted mono" style="font-size:12px;padding:6px 0">Belum ada event</div>



 <div v-for="ev in detailData.events" :key="ev.id" class="log-line" style="display:flex;justify-content:space-between;gap:8px"><span><b>{{ ev.nama }}</b> <span class="badge-status" :class="ev.hadir?'st-aktif':'st-nonaktif'" style="font-size:10px">{{ ev.hadir ? 'hadir' : ev.status }}</span></span><span class="mono muted" style="font-size:11px">{{ formatDate(ev.created_at) }}</span></div>



 </div>



 <div>



 <div class="field-label mono">SERTIFIKAT ({{ detailData.certs.length }})</div>



 <div v-if="!detailData.certs.length" class="muted mono" style="font-size:12px;padding:6px 0">Belum ada sertifikat</div>



 <div v-for="c in detailData.certs" :key="c.id" class="log-line"><span class="mono" style="font-size:11px">{{ c.tipe }} #{{ c.target_id }}</span> - <b class="mono" style="font-size:12px">{{ c.nomor }}</b> <span class="mono muted" style="font-size:11px"> - {{ formatDate(c.issued_at) }}</span> <span class="mono" style="font-size:10px;color:#6B7C85">{{ c.hash.slice(0,12) }}...</span></div>



 </div>



 <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">



 <button class="kat-mini neutral" @click="openEdit(detailData.user); showDetail=false">Edit</button>



 <button class="kat-mini neutral" @click="openReset(detailData.user)">Reset Password</button>



 <button class="kat-mini" :class="detailData.user.status==='aktif'?'warn':''" @click="toggleSuspend(detailData.user)">Toggle Suspend</button>



 </div>



 </template>



 </div>



 </div>



 </div>



 </div>



 </Teleport>







 <!-- Bulk Kelas Modal -->



 <Teleport to="body">



 <div v-if="showBulkKelas" class="fixed inset-0 z-50">



 <div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showBulkKelas=false"></div>



 <div class="absolute inset-0 grid place-items-center p-4">



 <div class="w-full max-w-[480px] rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden flex flex-col" style="border:1px solid #E0E5E3">



 <div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3"><h2 class="text-[16px] font-bold" style="color:#2F3E46">Bulk Buat Kelas</h2><button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showBulkKelas=false">x</button></div>



 <div class="p-6">



 <div class="field"><label class="field-label mono">KELAS *</label><input v-model="bulkKelasForm.kelas" placeholder="10A" class="kat-field-input" /></div>



 <div class="field"><label class="field-label mono">JUMLAH (1..100)</label><input v-model.number="bulkKelasForm.count" type="number" min="1" max="100" class="kat-field-input" /></div>



 <div class="grid-2">



 <div class="field"><label class="field-label mono">PREFIX EMAIL</label><input v-model="bulkKelasForm.prefix" placeholder="siswa" class="kat-field-input mono" /></div>



 <div class="field"><label class="field-label mono">DOMAIN</label><input v-model="bulkKelasForm.domain" placeholder="sekolah.test" class="kat-field-input mono" /></div>



 </div>



  <div class="field"><label class="field-label mono">PASSWORD (kosong = auto)</label><div style="display:flex;gap:6px"><div style="position:relative;flex:1"><input v-model="bulkKelasForm.password" placeholder="Siswa123!..." class="kat-field-input" /><button v-if="!bulkKelasForm.password" type="button" style="position:absolute;right:6px;top:50%;transform:translateY(-50%)" class="kat-mini neutral" @click="bulkKelasForm.password=genRandomPass()">Generate</button></div></div><div class="field-hint mono">Format: prefix + nomor + . + kelas@domain - cth: siswa1.10a@sekolah.test</div></div>



 <div v-if="bulkKelasResult" class="kat-alert ok mono" style="font-size:12px;white-space:pre-wrap">{{ bulkKelasResult }}</div>



 <div class="flex gap-2 justify-end mt-4"><button class="px-5 py-2.5 rounded-full border bg-white text-[13px] font-medium hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showBulkKelas=false">Batal</button><button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold disabled:opacity-40" style="background:#4A7875" @click="doBulkKelas" :disabled="bulkKelasLoading || !bulkKelasForm.kelas || bulkKelasForm.count<1">{{ bulkKelasLoading ? 'Membuat...' : 'Buat '+bulkKelasForm.count+' akun' }}</button></div>



 </div>



 </div>



 </div>



 </div>



 </Teleport>



</div>



</div>



<div v-if="showCreated" class="fixed inset-0 z-50">
<div class="absolute inset-0 bg-[#1a1a18]/40 backdrop-blur-[6px]" @click="showCreated=false"></div>
<div class="absolute inset-0 grid place-items-center p-4 overflow-auto">
<div :class="createdData?.bulkInfo?'max-w-[560px]':'max-w-[420px]'" class="w-full rounded-[20px] border bg-white shadow-[0_24px_64px_rgba(0,0,0,.18)] overflow-hidden" style="border:1px solid #E0E5E3">
<div class="px-6 py-4 border-b flex items-center justify-between" style="border-bottom:1px solid #E0E5E3">
<h2 class="text-[16px] font-bold" style="color:#2F3E46">{{ createdData?.bulkInfo ? 'Bulk Kelas Berhasil' : 'Akun Berhasil Dibuat' }}</h2>
<button class="w-8 h-8 rounded-full border grid place-items-center hover:bg-stone-50" style="border:1px solid #E0E5E3" @click="showCreated=false" aria-label="Tutup">x</button>
</div>
<div class="p-6">
<div v-if="!createdData?.bulkInfo" class="space-y-3">
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">NAMA</div><div style="font-size:14px;color:#2F3E46;font-weight:600">{{ createdData?.nama }}</div></div>
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">EMAIL</div><div class="mono" style="font-size:13px;color:#2F3E46;word-break:break-all">{{ createdData?.email }}</div></div>
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">PASSWORD</div><div class="mono" style="font-size:13px;color:#2F3E46;background:#F8FAF9;padding:8px 12px;border-radius:8px;border:1px solid #E0E5E3;word-break:break-all">{{ createdData?.password }}</div></div>
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">ROLE</div><div style="font-size:13px;color:#2F3E46">{{ createdData?.role }}</div></div>
<div v-if="createdData?.role==='siswa' && createdData?.kelas"><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">KELAS</div><div style="font-size:13px;color:#2F3E46">{{ createdData?.kelas }}</div></div>
<div v-if="createdData?.nip"><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">NIP</div><div class="mono" style="font-size:13px;color:#2F3E46">{{ createdData?.nip }}</div></div>
</div>
<div v-else class="space-y-3">
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">KELAS</div><div style="font-size:14px;color:#2F3E46;font-weight:600">{{ createdData?.kelas }}</div></div>
<div><div class="field-label mono" style="font-size:11px;color:#6B7C85;margin-bottom:2px">PASSWORD</div><div class="mono" style="font-size:13px;color:#2F3E46;background:#F8FAF9;padding:8px 12px;border-radius:8px;border:1px solid #E0E5E3;word-break:break-all">{{ createdData?.password }}</div></div>
<div style="max-height:240px;overflow-y:auto;border:1px solid #E0E5E3;border-radius:10px;background:#FAFBFC">
<table style="width:100%;font-size:12px;border-collapse:collapse">
<thead><tr style="position:sticky;top:0;background:#F0F4F3"><th style="text-align:left;padding:6px 10px;font-weight:600;color:#2F3E46">#</th><th style="text-align:left;padding:6px 10px;font-weight:600;color:#2F3E46">Email</th><th style="text-align:left;padding:6px 10px;font-weight:600;color:#2F3E46">Password</th></tr></thead>
<tbody><tr v-for="(c,i) in (createdData?.bulkInfo?.credentials||[])" :key="i" :style="i%2===0?'background:#fff':'background:#F8FAF9'"><td style="padding:5px 10px;color:#6B7C85">{{ i+1 }}</td><td class="mono" style="padding:5px 10px;color:#2F3E46;word-break:break-all">{{ c.email }}</td><td class="mono" style="padding:5px 10px;color:#2F3E46;word-break:break-all">{{ c.password }}</td></tr></tbody>
</table>
</div>
</div>
<div style="display:flex;align-items:center;gap:8px;margin-top:16px;padding:10px 12px;border:1px solid #E0E5E3;background:#F8FAF9;border-radius:10px">
<code class="mono" style="flex:1;font-size:11px;letter-spacing:.04em;word-break:break-all;color:#6B7C85;white-space:pre-line">{{ getCreatedText() }}</code>
<button type="button" class="kat-mini neutral" @click="copyCreatedData()" :class="{'!bg-[#4A7875] !text-white': createdCopied}" style="white-space:nowrap;flex-shrink:0">{{ createdCopied ? 'v Tercopy' : 'Copy' }}</button>
</div>
<div class="flex gap-2 justify-end mt-4">
<button class="px-5 py-2.5 rounded-full text-white text-[13px] font-semibold" style="background:#4A7875" @click="showCreated=false">Tutup</button>
</div>
</div>
</div>
</div>
</div>

</template>







<script setup>



import { ref, computed, watch, onMounted } from 'vue'



import { useRoute, useRouter } from 'vue-router'



import { api, getCsrf, getCsrfToken } from '../lib/api.js'







const route = useRoute()



const router = useRouter()







const q = ref((route.query.q ?? route.query.search ?? '').toString())



const role = ref((route.query.role ?? 'all').toString())



const kelasFilter = ref((route.query.kelas ?? 'all').toString())



const kelasList = ref([])



const statusFilter = ref((route.query.status ?? 'all').toString())



const sort = ref((route.query.sort ?? 'newest').toString())



const page = ref(Math.max(1, parseInt(route.query.page ?? '1')))



const limit = ref(Math.min(100, Math.max(1, parseInt(route.query.limit ?? '20'))))



const showArchived = ref(route.query.archived==='1')







const list = ref([])



const total = ref(0)



const pages = ref(1)



const stats = ref({ total: 0, siswa: 0, pembina: 0, admin_kepsek: 0 })



const archivedTotal = ref(0)



const loading = ref(false)



const error = ref('')



const toast = ref('')



const toastOk = ref(true)







const showModal = ref(false)



const editing = ref(null)



const form = ref({ nama:'', email:'', password:'', role:'siswa', kelas:'', nip:'' })



const formErr = ref('')



const showPass = ref(false)



const strength = ref({ score:0, w:0, label:'-' })



const editTab = ref('form')



const userLogs = ref([])







const showConfirm = ref(false)



const confirmTarget = ref(null)







const showReset = ref(false)



const resetTarget = ref(null)



const resetPass = ref('')



const showResetPass = ref(false)

const resetCopied = ref(false)
const showCreated = ref(false)
const createdData = ref(null)
const createdCopied = ref(false)







const showPurge = ref(false)



const purgeTarget = ref(null)



const purgeConfirm = ref('')







const selectedIds = ref([])



const bulkRole = ref('')



const showBulkConfirm = ref(false)



const bulkActionType = ref('')



const showArchiveBulkConfirm = ref(false)
const archiveBulkActionType = ref('')







const jumpInput = ref('')










const showLogs = ref(false)



const auditRows = ref([])



const logQ = ref('')



const logAction = ref('all')



const logPage = ref(1)







// Activity sparkline



const activity = ref({ labels:[], created:[], logins:[], days:14 })



const activityDays = ref(14)



const activityLoading = ref(false)



const activityCanvas = ref(null)



let activityChart = null







// Detail drawer



const showDetail = ref(false)



const detailData = ref(null)



const detailLoading = ref(false)







// Bulk kelas



const showBulkKelas = ref(false)



const bulkKelasForm = ref({ kelas:'', count:20, prefix:'siswa', domain:'sekolah.test', password:'' })



const bulkKelasLoading = ref(false)



const bulkKelasResult = ref('')







let debounceTimer = null







const siswaPct = computed(()=> stats.value.total ? Math.round(stats.value.siswa/stats.value.total*100) : 0)



const pembinaLabel = computed(()=> stats.value.pembina ? stats.value.pembina+' pembina' : '-')



const adminKepsekLabel = computed(()=> !stats.value.admin_kepsek ? '-' : stats.value.admin_kepsek + '')



const paginationInfo = computed(()=>{



 if(total.value===0) return '0 dari 0'



 const start=(page.value-1)*limit.value+1



 const end=Math.min(page.value*limit.value, total.value)



 return `${start}-${end} dari ${total.value}`



})



const totalPages = computed(()=> Math.max(1, Math.ceil(total.value/limit.value)))



const pageNumbers = computed(()=>{



 const t=totalPages.value, c=page.value, out=[]



 if(t<=7){ for(let i=1;i<=t;i++) out.push(i); return out } out.push(1); if(c>3) out.push('...')



 for(let i=Math.max(2,c-1); i<=Math.min(t-1,c+1); i++) out.push(i)



 if(c < t-2) out.push('...')



 out.push(t)



 return out



})



const allChecked = computed(()=> list.value.length>0 && selectedIds.value.length===list.value.length)



const isEmailValid = computed(()=> /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email || ''))



const isSaveDisabled = computed(()=>{



 if(!(form.value.nama||'').trim() || (form.value.nama||'').trim().length<2) return true



 if(!isEmailValid.value) return true



 if(!editing.value){



 if(!(form.value.password||'') || form.value.password.length<8) return true



 } else {



 if(form.value.password && form.value.password.length>0 && form.value.password.length<8) return true



 }



 if(!form.value.role) return true



 return false



})



const headSub = computed(()=>{



 const s=stats.value



 return `${s.total} akun`



})



const stripText = computed(()=>{



 if(showArchived.value) return `Arsip - ${archivedTotal.value} akun`



return `Aktif - ${total.value} akun`



})







function initials(n){ return (n||'').split(' ').map(s=>s[0]).join('').slice(0,2).toUpperCase() }



function formatDate(s){ if(!s) return '-'; return String(s).slice(0,10) }



function loginLabel(s){



 if(!s) return 'belum login'



 const d=new Date(s); if(isNaN(d)) return '-'



 const diff=Math.floor((Date.now()-d.getTime())/86400000)



 if(diff===0) return 'hari ini'



 if(diff===1) return '1 hari lalu'



 if(diff<7) return diff+' hari lalu'



 if(diff<30) return Math.floor(diff/7)+' minggu lalu'



 if(diff<365) return Math.floor(diff/30)+' bulan lalu'



 return formatDate(s)



}



function loginDot(s){



 if(!s) return '#d1d5db'



const d = new Date(s); if(isNaN(d)) return '#d1d5db'



const h = (Date.now()-d.getTime())/3600000



 if(h<24) return '#22c55e'



 if(h<24*7) return '#3b82f6'



 if(h<24*30) return '#f59e0b'



 return '#ef4444'



}



function loginCls(s){



 if(!s) return 'muted'



 const d = new Date(s); if(isNaN(d)) return 'muted'



 const h = (Date.now()-d.getTime())/3600000



 if(h<24) return 'login-recent'



 if(h<24*7) return 'login-week'



 if(h<24*30) return 'login-month'



 return 'login-old'



}



function genRandomPass(){



 const chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'



 let s=''; const arr=new Uint32Array(12); crypto.getRandomValues(arr); for(let i=0;i<12;i++) s+=chars[arr[i]%chars.length]



 // ensure syarat 8+ huruf besar+angka+symbol



 if(!/[A-Z]/.test(s)) s='A'+s.slice(1)



 if(!/[0-9]/.test(s)) s = s.slice(0,-1) + '7'



 if(!/[^A-Za-z0-9]/.test(s)) s = s.slice(0,-2) + '!X'



 return s



}



function genPassword(){



 const p = genRandomPass()



 form.value.password = p
 onPassInput()
}
function onPassInput(){
 const v = form.value.password || ''



 let s = 0; if(v.length >= 8) s++



 if(/[A-Z]/.test(v)) s++



 if(/[0-9]/.test(v)) s++



 if(/[^A-Za-z0-9]/.test(v)) s++



 const labels=['-','Lemah','Cukup','Kuat','Sangat kuat']



 strength.value={ score:s, w:[0,25,50,75,100][s], label: labels[s] }



}



function syncUrl(){



 const qobj={}



 if(q.value) qobj.q=q.value



 if(role.value && role.value!=='all') qobj.role=role.value



 if(kelasFilter.value && kelasFilter.value!=='all' && role.value==='siswa' && !showArchived.value) qobj.kelas=kelasFilter.value



 if(statusFilter.value && statusFilter.value!=='all') qobj.status=statusFilter.value



 if(sort.value && sort.value!=='newest') qobj.sort=sort.value



 if(page.value>1) qobj.page=String(page.value)



 if(limit.value!==20) qobj.limit=String(limit.value)



 if(showArchived.value) qobj.archived='1'



 router.replace({ query: qobj })



}



async function fetchKelasList(){



 try{



 const j=await api('/users/kelas-list')



 kelasList.value=j.data||[]



 }catch(e){ kelasList.value=[] }



}



async function load(){



 loading.value=true; error.value=''



 try{



 const params=new URLSearchParams()



 if(showArchived.value){



 if(q.value) params.set('q', q.value)



 params.set('page', String(page.value))



 params.set('limit', String(limit.value))



 syncUrl()



 const j=await api('/users/archived?'+params.toString())



 list.value=j.data||[]



 total.value=j.meta?.total ?? list.value.length



 pages.value=j.meta?.pages ?? Math.max(1, Math.ceil(total.value/limit.value))



 } else {



 if(q.value) params.set('q', q.value)



 if(role.value) params.set('role', role.value)



 if(!showArchived.value && role.value==='siswa' && kelasFilter.value && kelasFilter.value!=='all') params.set('kelas', kelasFilter.value)



 if(statusFilter.value) params.set('status', statusFilter.value)



 if(sort.value) params.set('sort', sort.value)



 params.set('page', String(page.value))



 params.set('limit', String(limit.value))



 syncUrl()



 const j=await api('/users?'+params.toString())



 list.value=j.data||[]



 total.value=j.meta?.total ?? list.value.length



 pages.value=j.meta?.pages ?? Math.max(1, Math.ceil(total.value/limit.value))



 if(j.meta?.stats) stats.value=j.meta.stats



 if(j.meta?.archivedTotal!==undefined) archivedTotal.value=j.meta.archivedTotal



 }



 }catch(e){



 error.value=e.error?.message || e.message || 'Gagal load'



 list.value=[]



 }finally{ loading.value=false }



}



function resetFilters(){ q.value=''; role.value='all'; kelasFilter.value='all'; statusFilter.value='all'; sort.value='newest'; page.value=1 }



function goPage(p){ page.value=Math.max(1,Math.min(p,totalPages.value)); load() }



function goJump(){ const n=parseInt(jumpInput.value); if(!isNaN(n)) goPage(n) }



function switchTab(arch){ showArchived.value=arch; page.value=1; selectedIds.value=[]; load() }



function toggleAll(){ if(allChecked.value) selectedIds.value=[]; else selectedIds.value=list.value.map(u=>u.id) }



function openCreate(){



 editing.value=null



 form.value={ nama:'', email:'', password:'', role:'siswa', kelas:'', nip:'' }



 formErr.value=''; showPass.value=false; strength.value={score:0,w:0,label:'-'}; editTab.value='form'



 showModal.value=true



}



function openEdit(u){



 editing.value=u.id



 form.value={ nama: u.nama, email: u.email, password:'', role: u.role, kelas: u.kelas||'', nip: u.nip||'' }



 formErr.value=''; showPass.value=false; strength.value={score:0,w:0,label:'-'}; editTab.value='form'; userLogs.value=[]



 showModal.value=true



}



function closeModal(){



 showModal.value=false; editing.value=null



 form.value={ nama:'', email:'', password:'', role:'siswa', kelas:'', nip:'' }



 formErr.value=''



}



function confirmDelete(u){ confirmTarget.value=u; showConfirm.value=true }



async function doDelete(){



 if(!confirmTarget.value) return



 try{



 await api('/users/'+confirmTarget.value.id, { method:'DELETE', body:{} })



 toast.value='Hapus berhasil (soft-delete)'; toastOk.value=true



 showConfirm.value=false



 await load()



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ toast.value=e.error?.message || 'Gagal hapus'; toastOk.value=false; setTimeout(()=> toast.value='', 2500) }



}



function openReset(u){ resetTarget.value=u; resetPass.value=''; showResetPass.value=false; resetCopied.value=false; forgotResult.value=null; forgotErr.value=''; forgotLoading.value=false; showReset.value=true }
function genResetPass(){ resetPass.value=genRandomPass(); showResetPass.value=true; resetCopied.value=false }
async function copyResetPass(){ if(!resetPass.value) return; try{ await navigator.clipboard.writeText(resetPass.value); resetCopied.value=true; setTimeout(()=> resetCopied.value=false,1800) }catch{ const ta=document.createElement('textarea'); ta.value=resetPass.value; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); resetCopied.value=true; setTimeout(()=> resetCopied.value=false,1800) } }
function getCreatedText(){
  if(!createdData.value) return ''
  const d=createdData.value
  if(d.bulkInfo){
    const creds=d.bulkInfo.credentials||[]
    if(!creds.length) return 'Bulk Kelas: '+d.kelas+'\nJumlah: '+d.bulkInfo.count+' akun\nPassword: '+d.password
    let t='Bulk Kelas: '+d.kelas+'\nPassword: '+d.password+'\n\n'
    creds.forEach((c,i)=>{ t+=(i+1)+'. '+c.email+' / '+c.password+'\n' })
    return t.trim()
  }
  let t='Nama: '+d.nama+'\nEmail: '+d.email+'\nPassword: '+d.password+'\nRole: '+d.role
  if(d.role==='siswa' && d.kelas) t+='\nKelas: '+d.kelas
  if((d.role==='pembina'||d.role==='kepsek') && d.nip) t+='\nNIP: '+d.nip
  return t
}
async function copyCreatedData(){
  const txt=getCreatedText()
  if(!txt) return
  try{ await navigator.clipboard.writeText(txt) }catch{ const ta=document.createElement('textarea'); ta.value=txt; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove() }
  createdCopied.value=true; setTimeout(()=> createdCopied.value=false,1800)
}



async function doReset(){



 if(!resetTarget.value) return



 if(resetPass.value.length<8) return



 try{



 await api('/users/'+resetTarget.value.id+'/reset-password', { method:'POST', body:{ password: resetPass.value } })



 toast.value='Reset password berhasil'; toastOk.value=true



 showReset.value=false



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ toast.value=e.error?.message || 'Gagal reset'; toastOk.value=false; setTimeout(()=> toast.value='', 2500) }



}

 // LUPA PASSWORD 2 METODE (tanpa SMTP): baca metode aktif -> forgot-reset; hasil bisa disalin
const forgotResult=ref(null), forgotErr=ref(''), forgotLoading=ref(false), forgotCopied=ref(false)
function forgotResultText(){
 if(!forgotResult.value) return ''
 const d=forgotResult.value
 if(d.method==='temp') return 'Password sementara untuk '+(''+(d.nama||''))+' <'+(''+(d.email||''))+'>:\n'+d.temp_password+'\nSampaikan manual ke siswa.'
 if(d.method==='link') return 'Link reset sekali pakai (30 menit) untuk '+(''+(d.nama||''))+' <'+(''+(d.email||''))+'>:\n'+d.reset_link
 return ''
}
async function copyForgotResult(){
 const t=forgotResultText(); if(!t) return
 try{ await navigator.clipboard.writeText(t) }catch{ const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove() }
 forgotCopied.value=true; setTimeout(()=> forgotCopied.value=false,1800)
}
async function doForgotReset(){
 if(!resetTarget.value) return
 forgotErr.value=''; forgotResult.value=null; forgotCopied.value=false; forgotLoading.value=true
 try{
 const j=await api('/users/'+resetTarget.value.id+'/forgot-reset', { method:'POST', body:{} })
 forgotResult.value=j.data||null
 }catch(e){ forgotErr.value=e?.error?.message || e?.message || 'Gagal reset' }
 finally{ forgotLoading.value=false }
}



async function toggleSuspend(u){



 try{



 const act = u.status==='aktif' ? 'suspend' : 'activate'



 await api('/users/'+u.id+'/suspend', { method:'POST', body:{ action: act } })



 toast.value= act==='suspend' ? 'Suspend berhasil' : 'Aktifkan berhasil'; toastOk.value=true



 await load()



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ toast.value=e.error?.message || 'Gagal'; toastOk.value=false }



}



async function doRestore(u){



 try{



 await api('/users/'+u.id+'/restore', { method:'POST', body:{} })



 toast.value='Restore berhasil'; toastOk.value=true



 await load()



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ toast.value=e.error?.message || 'Gagal restore'; toastOk.value=false }



}



function askPurge(u){ purgeTarget.value=u; purgeConfirm.value=''; showPurge.value=true }



async function doPurge(){



 if(!purgeTarget.value) return



 try{



 await api('/users/'+purgeTarget.value.id+'/purge', { method:'DELETE', body:{ confirm: purgeConfirm.value } })



 toast.value='Purge berhasil'; toastOk.value=true



 showPurge.value=false



 await load()



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ toast.value=e.error?.message || 'Gagal purge'; toastOk.value=false }



}



function bulkAction(action){



 bulkActionType.value=action



 if(action==='change_role' && !bulkRole.value){ toast.value='Pilih role dulu'; toastOk.value=false; return }



 if(action==='delete'){ showBulkConfirm.value=true; return }



 doBulk()



}



async function doBulk(){



 showBulkConfirm.value=false



 try{



 const body={ ids: selectedIds.value, action: bulkActionType.value }



 if(bulkActionType.value==='change_role') body.value=bulkRole.value



 const j=await api('/users/bulk', { method:'POST', body })



 const ok=(j.data?.results||[]).filter(r=>r.status==='ok').length



 toast.value=`Bulk ${bulkActionType.value}: ${ok} ok`; toastOk.value=true



 selectedIds.value=[]; bulkRole.value=''



 await load()



 setTimeout(()=> toast.value='', 2000)



 }catch(e){ toast.value=e.error?.message || 'Bulk gagal'; toastOk.value=false }



}

function archiveBulkAction(type) {
  archiveBulkActionType.value = type
  showArchiveBulkConfirm.value = true
}

async function doArchiveBulk() {
  const action = archiveBulkActionType.value
  const endpoint = action === 'restore' ? '/users/bulk-restore' : '/users/bulk-purge'
  try {
    const j = await api(endpoint, { method: 'POST', body: { ids: selectedIds.value } })
    toast.value = action === 'restore' ? `Berhasil restore ${j.data?.restored||0} user` : `Berhasil hapus ${j.data?.purged||0} user`
    toastOk.value = true
    selectedIds.value = []
    showArchiveBulkConfirm.value = false
    await load()
    setTimeout(()=> toast.value='', 1800)
  } catch(e) {
    toast.value = 'Gagal: ' + (e.message || 'error')
    toastOk.value = false
  }
}



async function doExport(){



 try{



 const params=new URLSearchParams()



 if(q.value) params.set('q', q.value)



 if(role.value && role.value!=='all') params.set('role', role.value)



 if(kelasFilter.value && kelasFilter.value!=='all' && role.value==='siswa' && !showArchived.value) params.set('kelas', kelasFilter.value)



 if(statusFilter.value && statusFilter.value!=='all') params.set('status', statusFilter.value)



 const token = getCsrfToken() || await getCsrf() || ''



  const base = (import.meta.env.VITE_API_BASE || '/api')



 const url = base + '/users/export?'+params.toString()



 const res = await fetch(url, { credentials:'include', headers: token? {'X-CSRF-Token': token}:{} })



 if(!res.ok) throw new Error('Export gagal '+res.status)



 const blob = await res.blob()



 const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='users-'+new Date().toISOString().slice(0,10)+'.csv'; a.click(); URL.revokeObjectURL(a.href)



 toast.value='Export berhasil'; toastOk.value=true; setTimeout(()=>toast.value='',1800)



 }catch(e){ toast.value=e.message||'Export gagal'; toastOk.value=false }



}



async function doBulkExport(){



 if(!selectedIds.value.length) return



 doExport()



}






async function loadAudit(){



 try{



 const p=new URLSearchParams()



 if(logQ.value) p.set('q', logQ.value)



 if(logAction.value && logAction.value!=='all') p.set('action', logAction.value)



 p.set('target','user'); p.set('page', String(logPage.value)); p.set('limit','20')



 const j=await api('/audit-logs?'+p.toString())



 auditRows.value=j.data||[]



 }catch(e){ auditRows.value=[] }



}



async function loadUserLogs(uid){



 editTab.value='logs'



 try{



 const j=await api('/users/'+uid+'/logs?limit=20')



 userLogs.value=j.data||[]



 }catch(e){ userLogs.value=[] }



}



async function save(){



 if(isSaveDisabled.value) return



 formErr.value=''



 try{



 const payload={}



 payload.nama=form.value.nama.trim()



 payload.email=form.value.email.trim()



 payload.role=form.value.role



 if(form.value.role==='siswa' && form.value.kelas) payload.kelas=form.value.kelas.trim()



 if((form.value.role==='pembina'||form.value.role==='kepsek') && form.value.nip) payload.nip=form.value.nip.trim()



 if(form.value.password) payload.password=form.value.password



 if(!editing.value){



 const j=await api('/users', { method:'POST', body: payload })



 createdData.value={ nama:payload.nama, email:payload.email, password:payload.password, role:payload.role, kelas:payload.kelas||'', nip:payload.nip||'', id:j.data?.id }
  closeModal()
  showCreated.value=true
  createdCopied.value=false
  setTimeout(()=> copyCreatedData(),300)
  await load()



 } else {



 await api('/users/'+editing.value, { method:'PATCH', body: payload })



 toast.value='Update berhasil'; toastOk.value=true



 }
  if(editing.value){
  await load()



 setTimeout(()=> toast.value='', 1800)
  }



 }catch(e){



 const msg=e.error?.message || 'Gagal simpan'



 formErr.value=msg



 toast.value=msg; toastOk.value=false



 setTimeout(()=> toast.value='', 3000)



 }



}







async function fetchActivity(){



 activityLoading.value=true



 try{



 const j=await api('/users/activity?days='+activityDays.value)



 activity.value=j.data||{labels:[],created:[],logins:[],days:activityDays.value}



 await renderActivity()



 }catch(e){ activity.value={labels:[],created:[],logins:[],days:activityDays.value} }



 finally{ activityLoading.value=false }



}



async function renderActivity(){



 const cv=activityCanvas.value; if(!cv) return



 const labels=activity.value.labels||[]; const created=activity.value.created||[]; const logins=activity.value.logins||[]



 if(!labels.length) return



 try{



 // ensure Chart.js



 if(!window.Chart){



 const existing=document.querySelector('script[data-chartjs]')



 if(!existing){



 const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js'; s.setAttribute('data-chartjs','1')



 await new Promise((res,rej)=>{ s.onload=res; s.onerror=rej; document.head.appendChild(s) })



 } else {



 // wait a bit if still loading



 let tries=0; while(!window.Chart && tries<20){ await new Promise(r=>setTimeout(r,100)); tries++ }



 }



 }



 if(!window.Chart) return



 // also try local import fallback



 if(activityChart){ try{ activityChart.destroy()}catch{}; activityChart=null }



 const ctx=cv.getContext('2d')



 activityChart=new window.Chart(ctx, {



 type:'line', data:{ labels: labels.map(d=> String(d).slice(5)), datasets:[



 { label:'Login', data: logins, borderColor:'#22c55e', backgroundColor:'rgba(34,197,94,.12)', fill:true, tension:.35, pointRadius:0, borderWidth:1.5 },



 { label:'Baru', data: created, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.08)', fill:true, tension:.35, pointRadius:0, borderWidth:1.5 }



 ]},



 options:{ responsive:true, maintainAspectRatio:false, animation:false, interaction:{mode:'index',intersect:false}, plugins:{ legend:{display:true, position:'top', labels:{boxWidth:12,font:{size:10}, color:'#6B7C85'}}, tooltip:{enabled:true}}, scales:{ y:{ beginAtZero:true, ticks:{precision:0, color:'#6B7C85', font:{size:10}}, grid:{color:'#f4f4f5'}}, x:{ ticks:{color:'#6B7C85',font:{size:9}, maxRotation:0, autoSkip:true, maxTicksLimit:7}, grid:{display:false}}}}



 })



 }catch(e){ console.warn('activity chart fail',e) }



}



async function openDetail(u){



 showDetail.value=true; detailLoading.value=true; detailData.value=null



 try{



 const j=await api('/users/'+u.id+'/detail')



 detailData.value=j.data



 }catch(e){ detailData.value=null }



 finally{ detailLoading.value=false }



}



async function doBulkKelas(){



 if(!bulkKelasForm.value.kelas || bulkKelasForm.value.count<1) return



 bulkKelasLoading.value=true; bulkKelasResult.value=''



 try{



 const j = await api('/users/bulk-kelas',{method:'POST', body:{ kelas: bulkKelasForm.value.kelas, count: Number(bulkKelasForm.value.count), prefix: bulkKelasForm.value.prefix||'siswa', domain: bulkKelasForm.value.domain||'sekolah.test', password: bulkKelasForm.value.password||'' }})



 bulkKelasResult.value = j.data.success+' berhasil, '+ (j.data.failed?.length||0)+' gagal - password: '+(j.data.password_hint||'auto')+' - '+(j.data.failed?.length? (j.data.failed[0]?.reason || 'gagal'):'semua OK')



 toast.value='Bulk kelas '+bulkKelasForm.value.kelas+': '+j.data.success+' akun dibuat'; toastOk.value=true
 await load(); await fetchKelasList(); await fetchActivity()
  const bPrefix=bulkKelasForm.value.prefix||'siswa'; const bDomain=bulkKelasForm.value.domain||'sekolah.test'; const bKelas=bulkKelasForm.value.kelas; const bSlug=bKelas.toLowerCase().replace(/[^a-z0-9]/g,''); const bPass=j.data.password_hint||'auto'; const bCount=j.data.success||0; const bCreds=[]; for(let i=1;i<=bCount;i++){ bCreds.push({ nama:'Siswa '+bKelas+' '+i, email:bPrefix+i+'.'+bSlug+'@'+bDomain, password:bPass }) }
  createdData.value={ nama:'Bulk Kelas '+bKelas, email:bCount+' akun dibuat', password:bPass, role:'bulk', kelas:bKelas, nip:'', bulkInfo:{ prefix:bPrefix, domain:bDomain, count:bCount, slug:bSlug, credentials:bCreds } }
  showBulkKelas.value=false
  showCreated.value=true
  createdCopied.value=false
  setTimeout(()=> copyCreatedData(),300)



 setTimeout(()=> toast.value='', 1800)



 }catch(e){ const msg=e.error?.message || e.message || 'Gagal'; bulkKelasResult.value='Error: '+msg; toast.value=msg; toastOk.value=false }



 finally{ bulkKelasLoading.value=false }



}



watch(q, ()=>{



 clearTimeout(debounceTimer)



 debounceTimer=setTimeout(()=>{ page.value=1; load() },300)



})



watch([role, kelasFilter, statusFilter, sort], ()=>{ page.value=1; load() })



watch([page, limit, showArchived], load)



watch(limit, ()=>{ page.value=1 })



watch(role, (v)=>{



 if(v!=='siswa' && kelasFilter.value!=='all') kelasFilter.value='all'



 if(v==='siswa') fetchKelasList()



})







onMounted(()=>{ fetchKelasList(); load(); fetchActivity(); handleResetQuery() })

 // LEGACY deep-link /admin/users?reset=<id> -> redirect ke halaman reset khusus
async function handleResetQuery(){
 const rid=parseInt(String(route.query.reset??''),10)
 if(!rid || isNaN(rid)) return
 router.replace('/admin/users/'+rid+'/reset')
}



</script>







<style scoped>



/* Mindora tokens - identical to /ekskul & /events & /kalender ( Katalog) */



.kat-page{



 --m-green:#5EB87E; --m-blue:#A7C7E7; --m-ink:#2F3E46; --m-bg:#F1F5F4; --m-pink:#E8AEB3;



 --m-cta:#4A7875; --m-cta-h:#5A908C; --m-line:#E0E5E3; --m-muted:#6B7C85;



 background:var(--m-bg);color:#2F3E46;



 margin:-24px calc(50% - 50vw) 0;padding:20px max(16px,calc(50vw - 680px)) 24px;



 max-width:100vw; box-sizing:border-box; overflow-x:clip; overflow-x:clip;



}



.kat-inner{max-width:1360px;margin:0 auto; min-width:0; max-width:100%; overflow-x:clip}



.mono{font-family:'Satoshi',system-ui,sans-serif}

.kat-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:2px 0 10px;flex-wrap:wrap}



.kat-title{margin:0;font-family:'Satoshi',system-ui,sans-serif;font-size:20px;font-weight:800;letter-spacing:-.01em}



.kat-sub{margin:2px 0 0;font-family:'Satoshi',system-ui,sans-serif;font-size:11.5px;color:#6B7C85}



.kat-head-r{display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}



.kat-link{padding:8px 14px;border-radius:10px;border:1px solid #E0E5E3;background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:#2F3E46;display:inline-flex;align-items:center;gap:6px;cursor:pointer}



.kat-link:hover{border-color:#c9cfcb;background:#fafafa}



.kat-link.strong{background:#4A7875;color:#fff;border-color:#4A7875}



.kat-link.strong:hover{background:var(--m-cta-h);border-color:var(--m-cta-h)}



.kat-strip{margin:0 0 10px;font-size:12.5px;background:#fff;border:1px solid #E0E5E3;border-left:3px solid #4A7875;border-radius:8px;padding:8px 12px}



.kat-strip a{color:#4A7875;font-weight:600}







/* stats */



.kat-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:12px}



@media(min-width:640px){.kat-stats{grid-template-columns:repeat(4,1fr)}}



.kat-stat{background:#fff;border:1px solid #E0E5E3;border-radius:12px;padding:14px 16px}



.kat-stat-label{font-size:10px;font-weight:700;letter-spacing:.1em;color:#6B7C85}



.kat-stat-value{font-size:22px;font-weight:800;letter-spacing:-.02em;margin-top:4px;color:#2F3E46}



.kat-stat-desc{font-size:11px;color:#6B7C85;margin-top:2px}







/* tabs as chips */



.kat-tabs{display:flex;gap:8px;margin-bottom:12px}



.kat-chip{padding:7px 14px;border-radius:999px;border:1px solid #E0E5E3;background:#fff;font-size:12.5px;font-weight:600;color:#2F3E46;cursor:pointer}



.kat-chip:hover{background:var(--m-bg)}



.kat-chip.active{background:#2F3E46;color:#fff;border-color:#2F3E46}



.kat-chip.small{padding:6px 10px;font-size:11.5px}



.kat-chip.small.active{background:#2F3E46;color:#fff}







/* toolbar sticky ( Katalog) */



.kat-toolbar{position:sticky;top:56px;z-index:10;background:var(--m-bg);border-bottom:1px solid #E0E5E3;padding:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}



.kat-search{position:relative;flex:0 1 280px;min-width:200px}



.kat-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6B7C85}



.kat-input{width:100%;padding:9px 36px 9px 34px;border:1px solid #E0E5E3;background:#fff;border-radius:10px;font-size:13px;outline:none;color:#2F3E46}



.kat-input:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.2)}



.kat-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:24px;height:24px;border-radius:999px;border:1px solid #E0E5E3;background:#fff;cursor:pointer;color:#6B7C85;line-height:1}



.kat-clear:hover{background:var(--m-bg)}



.kat-select{padding:9px 10px;border:1px solid #E0E5E3;background:#fff;border-radius:10px;font-size:12.5px;color:#2F3E46;min-height:38px;min-width:0}



.kat-select:disabled{opacity:.5;cursor:not-allowed;background:#f4f4f5}



.kat-select-field{padding:10px 12px;border-radius:12px;width:100%}



.kat-reset{padding:9px 14px;border:1px solid #E0E5E3;background:#fff;border-radius:10px;font-size:12.5px;font-weight:600;color:#2F3E46;min-height:38px;cursor:pointer}



.kat-reset:hover{background:var(--m-bg)}



.kat-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1 1 auto;min-width:0}



.kat-count-top{margin-left:auto;font-size:11px;color:#6B7C85;white-space:nowrap}







/* bulk */



.kat-bulk{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#2F3E46;color:#fff;padding:10px 12px;border-radius:12px;margin-bottom:12px}



.kat-bulk-count{font-size:12px;font-weight:600;color:#fff}



.kat-bulk .kat-select{border-color:rgba(255,255,255,.2);background:#fff;color:#2F3E46}



.kat-bulk .kat-mini{border-color:rgba(255,255,255,.2)}







/* card base */



.kat-card{background:#fff;border:1px solid #E0E5E3;border-radius:16px;overflow:hidden; max-width:100%; min-width:0; box-sizing:border-box}



.kat-table-card{padding:0; overflow:hidden}



.table-wrap{overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; scrollbar-width:thin; max-width:100%; min-width:0}



.desktop-table-wrap{display:block}



.users-table{width:100%;border-collapse:collapse;font-size:13px; min-width:720px}



.mobile-cards{display:none}



.users-table thead{background:#fafafa}



.users-table th{font-size:11px;font-weight:600;letter-spacing:.08em;color:#6B7C85;text-align:left;padding:12px 16px;white-space:nowrap}



.users-table td{padding:12px 16px;border-top:1px solid #f4f4f5;vertical-align:middle}



.row-hover:hover{background:#fafafa}



.user-cell{display:flex;align-items:center;gap:12px}



.avatar{width:32px;height:32px;border-radius:999px;background:#2F3E46;color:#fff;display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0}



.user-name{font-weight:600;line-height:1;color:#2F3E46}



.user-email{font-size:11px;color:#6B7C85;margin-top:2px}



.badge-role{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid transparent;font-weight:500;text-transform:lowercase}



.role-admin{background:#2F3E46;color:#fff;border-color:#2F3E46}



.role-pembina{background:#f0f9ff;color:#0369a1;border-color:#bae6fd}



.role-siswa{background:#ecfdf5;color:#047857;border-color:#a7f3d0}



.role-kepsek{background:#fffbeb;color:#92400e;border-color:#fde68a}



.badge-status{font-size:11px;padding:4px 8px;border-radius:999px;font-weight:500}



.badge-status.st-aktif{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}



.badge-status.st-suspended{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}



.badge-status.st-nonaktif{background:#f4f4f5;color:#6B7C85;border:1px solid #E0E5E3}



.badge-kelas{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid #E0E5E3;font-weight:600;background:var(--m-bg);color:#2F3E46}



.muted{color:#6B7C85}



.last-login{color:#a8a29e}



.actions{display:flex;justify-content:flex-end;gap:6px;flex-wrap:wrap}







/* kat-mini pills for row actions ( Katalog kat-mini) */



.kat-mini{padding:5px 10px;border-radius:8px;border:1px solid #E0E5E3;background:#fff;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;color:#2F3E46}



.kat-mini:hover{background:var(--m-bg)}



.kat-mini.neutral:hover{background:var(--m-bg)}



.kat-mini.danger{border-color:#fecaca;color:#991b1b}



.kat-mini.danger:hover{background:#fef2f2}



.kat-mini.warn{border-color:#fed7aa;color:#c2410c}



.kat-mini.warn:hover{background:#fff7ed}



.kat-mini.ghost{background:transparent;border-color:rgba(255,255,255,.3);color:#fff}



.kat-mini:disabled{opacity:.45;cursor:not-allowed}







.empty-cell{text-align:center;padding:40px 16px;color:#6B7C85}







/* paging inside table card */



.kat-paging{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #E0E5E3;font-size:12px;color:#6B7C85;gap:8px}



.kat-paging.inside{background:#fff}



.kat-paging-btns{display:flex;gap:6px;align-items:center;flex-wrap:wrap}



.kat-page-btn{padding:6px 12px;border:1px solid #E0E5E3;border-radius:999px;background:#fff;font-size:12px;cursor:pointer;color:#2F3E46}



.kat-page-btn:disabled{opacity:.5;cursor:not-allowed}



.kat-page-btn.active{background:#2F3E46;color:#fff;border-color:#2F3E46}



.page-ellipsis{padding:6px 4px;color:#6B7C85}



.jump-wrap{display:flex;align-items:center;gap:4px;margin-left:8px}



.jump-input{width:60px;padding:6px 8px;border:1px solid #E0E5E3;border-radius:8px;font-size:12px;background:#fff;color:#2F3E46}



.kat-count{color:#6B7C85}



.import-head{padding:12px 16px;font-size:13px;font-weight:600;border-bottom:1px solid #E0E5E3;background:#fff}



.progress{height:6px;background:var(--m-bg);border-radius:999px;overflow:hidden;margin:8px 16px}



.progress-bar{height:100%;background:var(--m-CTA, #4A7875);transition:width .3s;background:#4A7875}



.kat-alert{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:13px;border:1px solid}



.kat-alert.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}



.kat-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}



.kat-toast{position:fixed;bottom:16px;right:16px;padding:12px 16px;border-radius:12px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:60;border:1px solid}



.kat-toast.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}



.kat-toast.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}







.hide-sm{} .hide-md{}



@media(max-width:640px){.hide-sm{display:none}}



@media(max-width:768px){.hide-md{display:none}}



.skeleton{background:#e7eceb;border-radius:6px;animation:pulse 1.2s infinite}



@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}







/* modal field */



.field{margin-bottom:12px}



.field-label{font-size:11px;font-weight:600;letter-spacing:.08em;color:#6B7C85;display:block;margin-bottom:6px}



.kat-field-input{width:100%;padding:10px 12px;border:1px solid #E0E5E3;border-radius:12px;font-size:13px;outline:none;background:#fff;box-sizing:border-box;color:#2F3E46}



.kat-field-input:focus{border-color:var(--m-green);box-shadow:0 0 0 2px rgba(94,184,126,.2)}



.field-hint{font-size:11px;color:#6B7C85;margin-top:4px}



.field-error{font-size:11px;color:#dc2626;margin-top:4px}



.pass-wrap{position:relative}



.pass-input{padding-right:40px}



.btn-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:8px;border:none;background:transparent;cursor:pointer;display:grid;place-items:center;font-size:12px;color:#6B7C85}



.btn-eye:hover{background:var(--m-bg)}



.strength-track{height:6px;border-radius:999px;background:var(--m-bg);overflow:hidden;margin-top:8px}



.strength-bar{height:100%;transition:all .3s}



.s-0{background:#e4e4e7;width:0}



.s-1{background:#ef4444}



.s-2{background:#f59e0b}



.s-3{background:#0ea5e9}



.s-4{background:var(--m-green)}



.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}



.history-tabs{display:flex;gap:8px;margin:12px 0 8px}



.logs-wrap{max-height:200px;overflow:auto;border:1px solid #E0E5E3;border-radius:12px;padding:8px;background:var(--m-bg)}



.log-line{font-size:12px;padding:6px 0;border-bottom:1px solid #f4f4f5;color:#2F3E46}



.log-line:last-child{border:none}







@media(max-width:640px){



 .kat-page{



 padding:16px 14px 20px;



 margin:-24px -14px 0 -14px; /* no calc bleed on mobile */



 max-width:none; width:auto; overflow-x:clip;



 }



 .kat-head{flex-direction:column;align-items:flex-start;gap:8px}



 .kat-title{font-size:18px}



 .kat-head-r{width:100%; gap:6px}



 .kat-head-r .kat-link{flex:1 1 auto; justify-content:center; padding:8px 10px; font-size:12.5px}



 .kat-toolbar{position:static;top:auto;z-index:auto;flex-direction:column;align-items:stretch;padding:8px 0 6px;gap:6px;border-bottom:none}



 .kat-search{flex:none;width:100%;min-width:0}



 .kat-filters{display:grid;grid-template-columns:1fr 1fr;width:100%;flex:none;gap:6px}



 .kat-select{width:100%;padding:7px 10px;min-height:36px;border-radius:9px}



 .kat-input{padding:7px 30px 7px 30px;min-height:36px;border-radius:9px;font-size:13.5px}



 .kat-reset{padding:7px 12px;min-height:36px;border-radius:9px;font-size:12.5px}



 .kat-chip{min-height:32px;padding:6px 10px}



 .kat-clear{width:22px;height:22px}



 .kat-paging{flex-wrap:wrap; gap:6px}



 .kat-paging-btns{gap:4px}



 .jump-wrap{margin-left:0; width:100%; justify-content:flex-end}



 .grid-2{grid-template-columns:1fr}



 /* table -> cards switch: no horizontal bleed */



 .desktop-table-wrap{display:none !important}



 .mobile-cards{display:block !important; padding:8px 8px 4px; background:#fff}



 .mcard{border:1px solid #E0E5E3; border-radius:14px; padding:12px; margin-bottom:10px; background:#fff; box-shadow:0 1px 6px rgba(47,62,70,.06)}



 .mcard:last-child{margin-bottom:4px}



 .mcard-top{display:flex; align-items:center; gap:10px}



 .mcard-check{flex-shrink:0; display:grid; place-items:center}



 .mcard-check input{width:16px;height:16px; accent-color:#2F3E46}



 .mcard-avatar{width:36px;height:36px; font-size:11px; flex-shrink:0}



 .mcard-main{flex:1; min-width:0; overflow:hidden}



 .mcard-name{font-weight:700; font-size:13.5px; color:#2F3E46; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}



 .mcard-email{font-size:11px; color:#6B7C85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px}



 .mcard-go{width:28px;height:28px; border-radius:999px; border:1px solid #E0E5E3; background:#fafafa; color:#6B7C85; flex-shrink:0}



 .mcard-badges{display:flex; flex-wrap:wrap; gap:6px; margin:10px 0 8px}



 .mcard-badges .badge-role,.mcard-badges .badge-kelas,.mcard-badges .badge-status{font-size:11px; padding:3px 8px}



 .mcard-meta{font-size:11px; color:#6B7C85; display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:10px}



 .mcard-dot{opacity:.5}



 .mcard-actions{display:grid; grid-template-columns:1fr 1fr; gap:6px}



 .mcard-actions .kat-mini{width:100%; justify-content:center; padding:7px 8px; font-size:12px; min-height:32px}



 .skeleton-card{padding:0; border:none; box-shadow:none}



}



@media(min-width:641px){



 .mobile-cards{display:none !important}



 .desktop-table-wrap{display:block !important}



}



.kat-link:focus-visible,.kat-mini:focus-visible,.kat-page-btn:focus-visible,.kat-chip:focus-visible,.kat-clear:focus-visible,.kat-input:focus-visible,.kat-select:focus-visible,.kat-field-input:focus-visible{outline:2px solid var(--m-green);outline-offset:2px}



.login-dot{width:8px;height:8px;border-radius:999px;display:inline-block;flex-shrink:0}



.login-recent{color:#16a34a;font-weight:600}



.login-week{color:#2563eb}



.login-month{color:#d97706}



.login-old{color:#dc2626}



</style>




