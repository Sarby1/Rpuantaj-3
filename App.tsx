
import React, { useState, useMemo, useEffect } from 'react';
import { 
  Users, Clock, DollarSign, Trash2, Save, LayoutDashboard, History, Lock, ChevronRight, LogOut, UserCheck, ShieldCheck, ArrowLeft, Download, X, Calendar, Receipt, TrendingUp, Building2, Wallet, Edit3, Plus, Edit2, Settings, Send, CheckCircle2
} from 'lucide-react';
import { MOCK_EMPLOYEES } from './constants.ts';
import { Employee, AttendanceRecord } from './types.ts';

interface Payment {
  id: string;
  employeeId: string;
  amount: number;
  description: string;
  method: 'Banka/EFT' | 'Elden';
  date: string;
}

interface TelegramConfig {
  token: string;
  chatId: string;
}

export default function App() {
  const [view, setView] = useState<'landing' | 'public_entry' | 'admin_login' | 'admin_dashboard'>('landing');
  const [adminTab, setAdminTab] = useState<'dashboard' | 'logs' | 'personnel' | 'payments' | 'settings'>('dashboard');
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [password, setPassword] = useState('');
  const [selectedMonth, setSelectedMonth] = useState<string>(new Date().toISOString().slice(0, 7));
  
  const [employees, setEmployees] = useState<Employee[]>(() => {
    const saved = localStorage.getItem('p_pro_employees');
    return saved ? (JSON.parse(saved) as Employee[]) : MOCK_EMPLOYEES;
  });
  const [records, setRecords] = useState<AttendanceRecord[]>(() => {
    const saved = localStorage.getItem('p_pro_records');
    return saved ? (JSON.parse(saved) as AttendanceRecord[]) : [];
  });
  const [payments, setPayments] = useState<Payment[]>(() => {
    const saved = localStorage.getItem('p_pro_payments');
    return saved ? (JSON.parse(saved) as Payment[]) : [];
  });
  const [telegramConfig, setTelegramConfig] = useState<TelegramConfig>(() => {
    const saved = localStorage.getItem('p_pro_telegram');
    return saved ? JSON.parse(saved) : { token: '', chatId: '' };
  });

  const [paymentModal, setPaymentModal] = useState<{empId: string, name: string} | null>(null);
  const [editEmpModal, setEditEmpModal] = useState<Employee | null>(null);
  const [editLogModal, setEditLogModal] = useState<AttendanceRecord | null>(null);
  const [showAddForm, setShowAddForm] = useState(false);
  const [isTesting, setIsTesting] = useState(false);

  useEffect(() => {
    localStorage.setItem('p_pro_employees', JSON.stringify(employees));
    localStorage.setItem('p_pro_records', JSON.stringify(records));
    localStorage.setItem('p_pro_payments', JSON.stringify(payments));
    localStorage.setItem('p_pro_telegram', JSON.stringify(telegramConfig));
  }, [employees, records, payments, telegramConfig]);

  const sendTelegramNotification = async (message: string, customConfig?: TelegramConfig) => {
    const config = customConfig || telegramConfig;
    if (!config.token || !config.chatId) return false;
    try {
      const url = `https://api.telegram.org/bot${config.token}/sendMessage`;
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: config.chatId,
          text: message,
          parse_mode: 'HTML'
        })
      });
      return response.ok;
    } catch (error) {
      console.error('Telegram notification failed:', error);
      return false;
    }
  };

  const handleTestTelegram = async (e: React.MouseEvent) => {
    e.preventDefault();
    const form = (e.currentTarget as HTMLButtonElement).form;
    if (!form) return;
    const formData = new FormData(form);
    const testConfig = {
      token: formData.get('token') as string,
      chatId: formData.get('chatId') as string
    };

    if (!testConfig.token || !testConfig.chatId) {
      alert("Lütfen önce Token ve Chat ID alanlarını doldurun.");
      return;
    }

    setIsTesting(true);
    const success = await sendTelegramNotification(
      `<b>🔔 TEST MESAJI</b>\n\nPuantaj Pro Telegram bağlantısı başarıyla kuruldu!\n\n🕒 <b>Zaman:</b> ${new Date().toLocaleString('tr-TR')}`,
      testConfig
    );
    setIsTesting(false);

    if (success) {
      alert("Test mesajı başarıyla gönderildi! Lütfen Telegram uygulamanızı kontrol edin.");
    } else {
      alert("Test mesajı gönderilemedi. Lütfen Token ve Chat ID bilgilerini kontrol edin.");
    }
  };

  const handleSettingsSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    setTelegramConfig({
      token: formData.get('token') as string,
      chatId: formData.get('chatId') as string
    });
    alert("Ayarlar Başarıyla Kaydedildi!");
  };

  const handlePaymentSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!paymentModal) return;
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    const newPay: Payment = {
      id: Math.random().toString(36).substr(2, 9),
      employeeId: paymentModal.empId,
      amount: Number(formData.get('amount')),
      description: String(formData.get('description')),
      method: (formData.get('method') as 'Banka/EFT' | 'Elden') || 'Banka/EFT',
      date: new Date().toLocaleString('tr-TR')
    };
    setPayments(prev => [newPay, ...prev]);
    setPaymentModal(null);
    alert("Ödeme Kaydedildi!");
  };

  const handleEditEmpSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editEmpModal) return;
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    const name = formData.get('name') as string;
    const rate = Number(formData.get('hourlyRate'));
    
    setEmployees(prev => prev.map(emp => 
      emp.id === editEmpModal.id ? { ...emp, name, hourlyRate: rate } : emp
    ));
    setEditEmpModal(null);
    alert("Personel bilgileri güncellendi!");
  };

  const handleEditLogSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editLogModal) return;
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    const date = formData.get('date') as string;
    const start = formData.get('startTime') as string;
    const end = formData.get('endTime') as string;
    
    const emp = employees.find(x => x.id === editLogModal.employeeId);
    if (!emp) return;

    const [sH, sM] = start.split(':').map(Number);
    const [eH, eM] = end.split(':').map(Number);
    let diff = (eH * 60 + eM) - (sH * 60 + sM);
    if (diff < 0) diff += 1440;
    const hours = Number((diff / 60).toFixed(2));

    setRecords(prev => prev.map(rec => 
      rec.id === editLogModal.id 
        ? { ...rec, date, startTime: start, endTime: end, calculatedHours: hours, totalEarning: Number((hours * emp.hourlyRate).toFixed(2)) }
        : rec
    ));
    setEditLogModal(null);
    alert("Mesai kaydı güncellendi!");
  };

  const getEmpStats = (empId: string) => {
    const empRecords = records.filter(r => r.employeeId === empId);
    const empPayments = payments.filter(p => p.employeeId === empId);
    
    const totalHours = empRecords.reduce((sum, r) => sum + r.calculatedHours, 0);
    const totalEarned = empRecords.reduce((sum, r) => sum + r.totalEarning, 0);
    const totalPaid = empPayments.reduce((sum, p) => sum + p.amount, 0);
    const balance = totalEarned - totalPaid;

    return { totalHours, totalEarned, totalPaid, balance };
  };

  const groupedRecords = useMemo(() => {
    const filtered = records.filter(r => r.date && r.date.startsWith(selectedMonth));
    const groups: { [key: string]: AttendanceRecord[] } = {};
    filtered.sort((a,b) => b.date.localeCompare(a.date)).forEach(r => {
      if (!groups[r.date]) groups[r.date] = [];
      groups[r.date].push(r);
    });
    return groups;
  }, [records, selectedMonth]);

  const downloadCSV = () => {
    const rows = [['Tarih', 'Personel', 'Sure', 'Hakedis']];
    records.filter(r => r.date.startsWith(selectedMonth)).forEach(r => {
      rows.push([r.date, employees.find(e=>e.id===r.employeeId)?.name || '', String(r.calculatedHours), String(r.totalEarning)]);
    });
    const csvContent = "data:text/csv;charset=utf-8," + rows.map(e => e.join(";")).join("\n");
    const link = document.createElement("a");
    link.href = encodeURI(csvContent);
    link.download = `puantaj_${selectedMonth}.csv`;
    link.click();
  };

  if (view === 'landing') {
    return (
      <div className="h-screen flex items-center justify-center bg-slate-950 p-6 text-white overflow-hidden">
        <div className="max-w-md w-full text-center space-y-12">
          <div className="space-y-4">
            <div className="w-20 h-20 bg-indigo-600 rounded-3xl mx-auto flex items-center justify-center shadow-2xl rotate-12"><TrendingUp className="w-10 h-10" /></div>
            <h1 className="text-4xl font-black italic tracking-tighter uppercase">PUANTAJ<span className="text-indigo-500">PRO</span></h1>
            <p className="text-slate-400 font-medium italic">VDS Edition • Profesyonel Takip</p>
          </div>
          <div className="grid gap-4">
            <button onClick={() => setView('public_entry')} className="bg-white p-6 rounded-[2rem] flex items-center justify-between text-slate-900 shadow-xl hover:scale-105 transition-all">
              <div className="flex items-center space-x-4"><UserCheck className="text-indigo-600" /><div className="text-left font-black uppercase text-sm italic">Mesai Girişi</div></div><ChevronRight />
            </button>
            <button onClick={() => setView('admin_login')} className="bg-slate-900 border border-slate-800 p-6 rounded-[2rem] flex items-center justify-between shadow-2xl hover:border-indigo-500 transition-all text-white">
              <div className="flex items-center space-x-4"><Lock className="text-slate-400" /><div className="text-left font-black uppercase text-sm italic">Yönetici Paneli</div></div><ShieldCheck />
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (view === 'public_entry') {
    return (
      <div className="h-screen bg-slate-950 p-6 text-white flex flex-col items-center justify-center">
        <button onClick={() => setView('landing')} className="mb-8 flex items-center space-x-2 text-slate-500 hover:text-white transition-colors">
          <ArrowLeft className="w-4 h-4" /><span className="font-black text-[10px] uppercase tracking-widest italic">Anasayfa</span>
        </button>
        <div className="max-w-md w-full bg-slate-900 p-10 rounded-[3rem] border border-slate-800 shadow-2xl">
          <h2 className="text-3xl font-black italic uppercase tracking-tighter mb-10 text-center">MESAİ <span className="text-indigo-500">KAYDI</span></h2>
          <form onSubmit={async (e) => {
            e.preventDefault();
            const fd = new FormData(e.currentTarget as HTMLFormElement);
            const empId = fd.get('employeeId') as string;
            const date = fd.get('date') as string;
            const start = fd.get('startTime') as string;
            const end = fd.get('endTime') as string;
            const emp = employees.find(x=>x.id===empId);
            if (!emp) return;
            const overlap = records.some(r => r.employeeId === empId && r.date === date && ((start < r.endTime) && (end > r.startTime)));
            if (overlap) { alert("Hata: Çakışan saat!"); return; }
            const [sH, sM] = start.split(':').map(Number); const [eH, eM] = end.split(':').map(Number);
            let diff = (eH*60+eM)-(sH*60+sM); if(diff<0) diff+=1440;
            const hours = Number((diff/60).toFixed(2));
            setRecords(prev => [{id: Math.random().toString(), employeeId: empId, date, startTime: start, endTime: end, calculatedHours: hours, totalEarning: Number((hours*emp.hourlyRate).toFixed(2))}, ...prev]);
            
            // Telegram Bildirimi
            const message = `<b>✅ YENİ MESAİ KAYDI</b>\n\n👤 <b>Personel:</b> ${emp.name}\n📅 <b>Tarih:</b> ${new Date(date).toLocaleDateString('tr-TR')}\n⏰ <b>Saat:</b> ${start} - ${end}\n⏳ <b>Süre:</b> ${hours} Saat`;
            await sendTelegramNotification(message);

            alert("Kaydedildi!"); setView('landing');
          }} className="space-y-6">
            <select name="employeeId" className="w-full p-4 bg-slate-950 border border-slate-800 rounded-2xl outline-none font-bold text-white" required>
              <option value="">İsim Seçin...</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
            </select>
            <input type="date" name="date" defaultValue={new Date().toISOString().split('T')[0]} className="w-full p-4 bg-slate-950 border border-slate-800 rounded-2xl outline-none font-bold text-white" required />
            <div className="grid grid-cols-2 gap-4">
              <input type="time" name="startTime" defaultValue="08:00" className="w-full p-4 bg-slate-950 border border-slate-800 rounded-2xl outline-none font-bold text-white" required />
              <input type="time" name="endTime" defaultValue="17:00" className="w-full p-4 bg-slate-950 border border-slate-800 rounded-2xl outline-none font-bold text-white" required />
            </div>
            <button type="submit" className="w-full py-6 bg-indigo-600 text-white rounded-[2rem] font-black uppercase tracking-widest text-sm shadow-xl shadow-indigo-900/20 mt-4 hover:bg-indigo-700 transition-all">KAYDI GÖNDER</button>
          </form>
        </div>
      </div>
    );
  }

  if (view === 'admin_login') {
    return (
      <div className="h-screen bg-slate-950 flex items-center justify-center p-6 text-white text-center">
        <div className="max-w-md w-full space-y-8 bg-slate-900 p-12 rounded-[3rem] border border-slate-800">
          <div className="w-16 h-16 bg-slate-950 border border-slate-800 rounded-2xl mx-auto flex items-center justify-center mb-4"><Lock className="w-8 h-8 text-indigo-500" /></div>
          <h2 className="text-3xl font-black italic uppercase tracking-tighter">ADMİN <span className="text-indigo-500">GİRİŞİ</span></h2>
          <form onSubmit={e => { e.preventDefault(); if(password==='admin123'){setIsAuthenticated(true); setView('admin_dashboard');} else alert("Hata!"); }} className="space-y-4">
            <input type="password" placeholder="Şifre" value={password} onChange={e => setPassword(e.target.value)} className="w-full p-5 bg-slate-950 border border-slate-800 rounded-3xl outline-none font-black text-center text-xl tracking-widest focus:border-indigo-500" autoFocus />
            <button type="submit" className="w-full py-5 bg-indigo-600 text-white rounded-3xl font-black uppercase tracking-widest text-sm shadow-xl shadow-indigo-900/20">SİSTEME GİRİŞ YAP</button>
            <button onClick={() => setView('landing')} className="text-slate-500 text-[10px] uppercase font-black tracking-widest italic">Vazgeç</button>
          </form>
        </div>
      </div>
    );
  }

  if (view === 'admin_dashboard' && isAuthenticated) {
    return (
      <div className="flex h-screen bg-slate-50 font-sans">
        <aside className="w-72 bg-slate-950 text-white flex flex-col shadow-2xl shrink-0 p-8 z-20">
          <div className="mb-12 flex items-center space-x-3"><div className="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg"><ShieldCheck className="w-6 h-6" /></div><h1 className="text-2xl font-black italic tracking-tighter uppercase">ADMİN<span className="text-indigo-500">PRO</span></h1></div>
          <nav className="flex-1 space-y-3">
            <SidebarItem icon={<LayoutDashboard />} label="Özet Panel" active={adminTab === 'dashboard'} onClick={() => setAdminTab('dashboard')} />
            <SidebarItem icon={<Receipt />} label="Ödeme Geçmişi" active={adminTab === 'payments'} onClick={() => setAdminTab('payments')} />
            <SidebarItem icon={<History />} label="Mesai Kayıtları" active={adminTab === 'logs'} onClick={() => setAdminTab('logs')} />
            <SidebarItem icon={<Users />} label="Personel Listesi" active={adminTab === 'personnel'} onClick={() => setAdminTab('personnel')} />
            <SidebarItem icon={<Settings />} label="Ayarlar" active={adminTab === 'settings'} onClick={() => setAdminTab('settings')} />
          </nav>
          <button onClick={() => { setIsAuthenticated(false); setView('landing'); }} className="mt-auto p-4 bg-rose-500/10 text-rose-500 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-500 hover:text-white transition-all">Güvenli Çıkış</button>
        </aside>

        <main className="flex-1 flex flex-col relative overflow-hidden">
          {/* ÖDEME MODALI */}
          {paymentModal && (
            <div className="absolute inset-0 z-50 bg-slate-950/80 flex items-center justify-center p-6 backdrop-blur-sm">
              <div className="bg-white w-full max-w-md p-10 rounded-[3rem] shadow-2xl animate-in zoom-in-95">
                <div className="flex justify-between items-center mb-6"><h3 className="text-2xl font-black italic uppercase tracking-tighter">Ödeme Kaydı Yap</h3><button onClick={() => setPaymentModal(null)}><X className="text-slate-300" /></button></div>
                <form onSubmit={handlePaymentSubmit} className="space-y-6">
                  <div className="p-3 bg-slate-50 rounded-xl flex justify-between font-black text-indigo-600 uppercase text-[10px] tracking-widest"><span className="text-slate-400">Alıcı:</span> {paymentModal.name}</div>
                  <div className="space-y-2"><label className="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Yöntem</label><div className="grid grid-cols-2 gap-3">
                    <label className="cursor-pointer group"><input type="radio" name="method" value="Banka/EFT" className="peer hidden" defaultChecked /><div className="p-3 border-2 border-slate-100 rounded-xl text-center peer-checked:border-indigo-600 peer-checked:bg-indigo-50 peer-checked:text-indigo-600"><Building2 className="w-5 h-5 mx-auto mb-1" /><span className="text-[8px] font-black uppercase">Banka/EFT</span></div></label>
                    <label className="cursor-pointer group"><input type="radio" name="method" value="Elden" className="peer hidden" /><div className="p-3 border-2 border-slate-100 rounded-xl text-center peer-checked:border-indigo-600 peer-checked:bg-indigo-50 peer-checked:text-indigo-600"><Wallet className="w-5 h-5 mx-auto mb-1" /><span className="text-[8px] font-black uppercase">Elden</span></div></label>
                  </div></div>
                  <input name="amount" type="number" step="0.01" defaultValue={Math.max(0, getEmpStats(paymentModal.empId).balance).toFixed(2)} className="w-full p-5 bg-slate-50 rounded-2xl outline-none font-black text-2xl text-indigo-600" required />
                  <textarea name="description" placeholder="Açıklama / Notlar" className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold h-20 text-sm" required></textarea>
                  <button type="submit" className="w-full py-5 bg-indigo-600 text-white rounded-3xl font-black uppercase tracking-widest text-sm shadow-xl shadow-indigo-100">Ödemeyi Onayla</button>
                </form>
              </div>
            </div>
          )}

          {/* PERSONEL DÜZENLEME MODALI */}
          {editEmpModal && (
            <div className="absolute inset-0 z-50 bg-slate-950/80 flex items-center justify-center p-6 backdrop-blur-sm">
              <div className="bg-white w-full max-w-md p-10 rounded-[3rem] shadow-2xl animate-in zoom-in-95">
                <div className="flex justify-between items-center mb-6"><h3 className="text-2xl font-black italic uppercase tracking-tighter">Personel Düzenle</h3><button onClick={() => setEditEmpModal(null)}><X className="text-slate-300" /></button></div>
                <form onSubmit={handleEditEmpSubmit} className="space-y-6">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black uppercase text-slate-400 ml-2">Personel Adı</label>
                    <input name="name" type="text" defaultValue={editEmpModal.name} className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold text-lg" required />
                  </div>
                  <div className="space-y-2">
                    <label className="text-[10px] font-black uppercase text-slate-400 ml-2">Saatlik Ücret (₺)</label>
                    <input name="hourlyRate" type="number" defaultValue={editEmpModal.hourlyRate} className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold text-lg" required />
                  </div>
                  <button type="submit" className="w-full py-5 bg-slate-950 text-white rounded-3xl font-black uppercase tracking-widest text-sm shadow-xl">GÜNCELLE</button>
                </form>
              </div>
            </div>
          )}

          {/* MESAI DÜZENLEME MODALI */}
          {editLogModal && (
            <div className="absolute inset-0 z-50 bg-slate-950/80 flex items-center justify-center p-6 backdrop-blur-sm">
              <div className="bg-white w-full max-w-md p-10 rounded-[3rem] shadow-2xl animate-in zoom-in-95">
                <div className="flex justify-between items-center mb-6"><h3 className="text-2xl font-black italic uppercase tracking-tighter">Mesai Kaydı Düzenle</h3><button onClick={() => setEditLogModal(null)}><X className="text-slate-300" /></button></div>
                <form onSubmit={handleEditLogSubmit} className="space-y-6">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black uppercase text-slate-400 ml-2">Tarih</label>
                    <input name="date" type="date" defaultValue={editLogModal.date} className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold text-lg" required />
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <label className="text-[10px] font-black uppercase text-slate-400 ml-2">Giriş</label>
                      <input name="startTime" type="time" defaultValue={editLogModal.startTime} className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold text-lg" required />
                    </div>
                    <div className="space-y-2">
                      <label className="text-[10px] font-black uppercase text-slate-400 ml-2">Çıkış</label>
                      <input name="endTime" type="time" defaultValue={editLogModal.endTime} className="w-full p-4 bg-slate-50 rounded-2xl outline-none font-bold text-lg" required />
                    </div>
                  </div>
                  <button type="submit" className="w-full py-5 bg-indigo-600 text-white rounded-3xl font-black uppercase tracking-widest text-sm shadow-xl">KAYDI GÜNCELLE</button>
                </form>
              </div>
            </div>
          )}

          <header className="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-10">
            <h2 className="text-[10px] font-black text-slate-400 uppercase tracking-widest italic">{adminTab} GÖRÜNÜMÜ</h2>
            <div className="flex items-center space-x-4">
               <input type="month" value={selectedMonth} onChange={e => setSelectedMonth(e.target.value)} className="bg-slate-100 px-4 py-2 rounded-xl text-[10px] font-black uppercase italic cursor-pointer" />
               <button onClick={downloadCSV} className="bg-slate-950 text-white px-6 py-2 rounded-xl text-[10px] font-black uppercase shadow-md"><Download className="w-4 h-4 inline mr-2" />CSV İNDİR</button>
            </div>
          </header>

          <div className="flex-1 overflow-y-auto p-10 custom-scrollbar">
            {adminTab === 'dashboard' && (
              <div className="max-w-6xl mx-auto space-y-6">
                <h3 className="text-3xl font-black italic uppercase tracking-tighter text-slate-900">Hakediş <span className="text-indigo-600">Özeti</span></h3>
                <div className="bg-white rounded-[3rem] shadow-xl overflow-hidden border border-slate-100">
                  <table className="w-full text-left">
                    <thead className="bg-slate-950 text-white text-[10px] font-black uppercase italic tracking-widest">
                      <tr>
                        <th className="px-10 py-6">Personel</th>
                        <th className="px-6 py-6">S. Ücret</th>
                        <th className="px-6 py-6">T. Saat</th>
                        <th className="px-6 py-6 text-emerald-400">T. Kazanç</th>
                        <th className="px-6 py-6 text-rose-400">Ödenen</th>
                        <th className="px-6 py-6 text-indigo-400">Bakiye</th>
                        <th className="px-10 py-6 text-center">İşlem</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                      {employees.map(emp => {
                        const stats = getEmpStats(emp.id);
                        return (
                          <tr key={emp.id} className="hover:bg-slate-50 transition-colors">
                            <td className="px-10 py-7 font-black text-slate-900 text-xl uppercase italic">{emp.name}</td>
                            <td className="px-6 py-7 font-bold text-slate-400 text-lg">₺{emp.hourlyRate}</td>
                            <td className="px-6 py-7 font-black text-slate-900 text-xl">{stats.totalHours.toFixed(2)} <span className="text-[10px] text-slate-300">sa</span></td>
                            <td className="px-6 py-7 font-bold text-emerald-600 text-xl">₺{stats.totalEarned.toFixed(2)}</td>
                            <td className="px-6 py-7 font-bold text-rose-500 text-xl">₺{stats.totalPaid.toFixed(2)}</td>
                            <td className="px-6 py-7 font-black text-indigo-600 text-2xl italic">₺{stats.balance.toFixed(2)}</td>
                            <td className="px-10 py-7 text-center">
                              <button onClick={() => setPaymentModal({empId: emp.id, name: emp.name})} className="bg-indigo-600 text-white px-6 py-2 rounded-xl font-black text-[10px] uppercase shadow-lg shadow-indigo-100">ÖDEME YAP</button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {adminTab === 'logs' && (
              <div className="max-w-6xl mx-auto space-y-8">
                <h3 className="text-3xl font-black italic uppercase tracking-tighter text-slate-900">Mesai <span className="text-indigo-600">Logları</span></h3>
                {Object.keys(groupedRecords).length === 0 ? (
                  <div className="p-20 text-center text-slate-300 font-black italic text-xl uppercase border-4 border-dashed border-slate-100 rounded-[3rem]">Kayıt bulunamadı.</div>
                ) : (
                  (Object.entries(groupedRecords) as [string, AttendanceRecord[]][]).map(([date, dayRecords]) => (
                    <div key={date}>
                      <h4 className="font-black text-slate-400 text-[10px] mb-3 uppercase italic ml-4 flex items-center tracking-widest"><Calendar className="w-4 h-4 mr-2" />{new Date(date).toLocaleDateString('tr-TR')}</h4>
                      <div className="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table className="w-full text-left">
                          <tbody className="divide-y divide-slate-50">
                            {dayRecords.map(record => (
                              <tr key={record.id} className="hover:bg-slate-50">
                                <td className="px-8 py-6 font-black text-slate-900 italic uppercase w-1/3">{employees.find(e=>e.id===record.employeeId)?.name}</td>
                                <td className="px-8 py-6 text-xs font-black text-slate-400 italic">{record.startTime} - {record.endTime}</td>
                                <td className="px-8 py-6 font-black text-indigo-500 italic">{record.calculatedHours} Sa</td>
                                <td className="px-8 py-6 text-right font-black italic">₺{record.totalEarning.toFixed(2)}</td>
                                <td className="px-8 py-6 text-center">
                                  <div className="flex items-center justify-center space-x-2">
                                    <button onClick={() => setEditLogModal(record)} className="text-indigo-400 hover:text-indigo-600 transition-colors p-2"><Edit2 className="w-4 h-4" /></button>
                                    <button onClick={() => {if(confirm("Bu kaydı silmek istediğinize emin misiniz?")) setRecords(prev => prev.filter(x=>x.id!==record.id));}} className="text-rose-200 hover:text-rose-600 transition-colors p-2"><Trash2 className="w-4 h-4" /></button>
                                  </div>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}

            {adminTab === 'payments' && (
              <div className="max-w-6xl mx-auto space-y-6">
                <h3 className="text-3xl font-black italic uppercase tracking-tighter text-slate-900">Ödeme <span className="text-emerald-600">Geçmişi</span></h3>
                <div className="bg-white rounded-[3rem] shadow-xl overflow-hidden border border-slate-100">
                  <table className="w-full text-left">
                    <thead className="bg-slate-950 text-white text-[10px] font-black uppercase italic tracking-widest">
                      <tr><th className="px-10 py-6">Tarih</th><th className="px-10 py-6">Personel</th><th className="px-10 py-6">Yöntem</th><th className="px-10 py-6">Açıklama</th><th className="px-10 py-6 text-right">Tutar</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                      {payments.length === 0 ? <tr><td colSpan={5} className="px-10 py-20 text-center text-slate-300 font-black italic uppercase">Henüz ödeme kaydı yok.</td></tr> : payments.map(p => (
                        <tr key={p.id} className="hover:bg-slate-50 transition-colors">
                          <td className="px-10 py-7 text-xs font-bold text-slate-400">{p.date}</td>
                          <td className="px-10 py-7 font-black text-slate-900 uppercase italic">{employees.find(e=>e.id===p.employeeId)?.name}</td>
                          <td className="px-10 py-7"><span className={`px-2 py-1 rounded-full text-[8px] font-black uppercase tracking-tighter ${p.method === 'Elden' ? 'bg-orange-100 text-orange-600' : 'bg-indigo-100 text-indigo-600'}`}>{p.method}</span></td>
                          <td className="px-10 py-7 text-sm text-slate-600 italic">{p.description}</td>
                          <td className="px-10 py-7 text-right font-black text-rose-600 italic">- ₺{p.amount.toFixed(2)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {adminTab === 'personnel' && (
              <div className="max-w-6xl mx-auto space-y-6">
                <div className="flex justify-between items-center mb-6">
                   <h3 className="text-3xl font-black italic uppercase tracking-tighter text-slate-900">Personel <span className="text-indigo-600">Yönetimi</span></h3>
                   <button onClick={() => setShowAddForm(!showAddForm)} className="bg-slate-950 text-white px-8 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg hover:bg-indigo-600 transition-all flex items-center">
                     <Plus className="w-4 h-4 mr-2" /> YENİ PERSONEL
                   </button>
                </div>

                {showAddForm && (
                   <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 mb-8 shadow-sm animate-in slide-in-from-top-4">
                     <form onSubmit={(e) => {
                       e.preventDefault();
                       const fd = new FormData(e.currentTarget as HTMLFormElement);
                       const name = fd.get('name') as string;
                       const rate = Number(fd.get('rate'));
                       setEmployees(prev => [...prev, {id: Math.random().toString(36).substr(2,9), name, hourlyRate: rate}]);
                       setShowAddForm(false);
                     }} className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                       <div className="space-y-2">
                         <label className="text-[10px] font-bold uppercase text-slate-400 ml-2">Tam İsim</label>
                         <input name="name" placeholder="Ad Soyad..." className="w-full p-4 bg-slate-50 border border-slate-100 rounded-xl outline-none font-bold" required />
                       </div>
                       <div className="space-y-2">
                         <label className="text-[10px] font-bold uppercase text-slate-400 ml-2">Saatlik Ücret (₺)</label>
                         <input name="rate" type="number" placeholder="Örn: 150" className="w-full p-4 bg-slate-50 border border-slate-100 rounded-xl outline-none font-bold" required />
                       </div>
                       <button type="submit" className="py-4 bg-indigo-600 text-white rounded-xl font-black uppercase text-xs tracking-widest shadow-lg">PERSONEL EKLE</button>
                     </form>
                   </div>
                )}

                <div className="bg-white rounded-[3rem] shadow-2xl overflow-hidden border border-slate-100">
                  <table className="w-full text-left">
                    <thead className="bg-slate-950 text-white text-[10px] font-black uppercase italic tracking-widest">
                      <tr><th className="px-10 py-6">Ad Soyad</th><th className="px-10 py-6">Ücret</th><th className="px-10 py-6 text-center">İşlem</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                      {employees.map(emp => (
                        <tr key={emp.id} className="hover:bg-slate-50 transition-colors">
                          <td className="px-10 py-7 font-black text-slate-900 text-2xl uppercase italic">{emp.name}</td>
                          <td className="px-10 py-7 font-black text-indigo-600 text-2xl italic">₺{emp.hourlyRate} <span className="text-[10px] text-slate-300">/ sa</span></td>
                          <td className="px-10 py-7 text-center">
                            <div className="flex items-center justify-center space-x-3">
                              <button onClick={() => setEditEmpModal(emp)} className="p-3 bg-indigo-50 text-indigo-600 rounded-xl hover:bg-indigo-600 hover:text-white transition-all shadow-sm">
                                <Edit3 className="w-5 h-5" />
                              </button>
                              <button onClick={() => {if(confirm("Bu personeli silmek istediğinize emin misiniz?")) setEmployees(prev => prev.filter(e=>e.id!==emp.id));}} className="p-3 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-500 hover:text-white transition-all shadow-sm">
                                <Trash2 className="w-5 h-5" />
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {adminTab === 'settings' && (
              <div className="max-w-4xl mx-auto space-y-8">
                <div className="flex items-center justify-between">
                  <h3 className="text-3xl font-black italic uppercase tracking-tighter text-slate-900">Sistem <span className="text-indigo-600">Ayarları</span></h3>
                </div>
                
                <div className="bg-white p-12 rounded-[3.5rem] shadow-2xl border border-slate-100">
                  <div className="flex items-center space-x-4 mb-10">
                    <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl"><Send className="w-8 h-8" /></div>
                    <div>
                      <h4 className="text-xl font-black uppercase italic tracking-tight">Telegram Bildirimleri</h4>
                      <p className="text-xs font-medium text-slate-400">Yeni mesai kayıtlarında anlık bildirim almanız için gereklidir.</p>
                    </div>
                  </div>

                  <form onSubmit={handleSettingsSubmit} className="space-y-8">
                    <div className="space-y-3">
                      <label className="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Telegram Bot Token (BotFather)</label>
                      <input 
                        name="token" 
                        type="text" 
                        defaultValue={telegramConfig.token}
                        placeholder="Örn: 123456:ABC-DEF..."
                        className="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-slate-700 transition-all" 
                      />
                    </div>
                    <div className="space-y-3">
                      <label className="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Telegram Chat ID (UserID / GrupID)</label>
                      <input 
                        name="chatId" 
                        type="text" 
                        defaultValue={telegramConfig.chatId}
                        placeholder="Örn: 987654321"
                        className="w-full p-5 bg-slate-50 border-2 border-transparent focus:border-indigo-500 rounded-2xl outline-none font-bold text-slate-700 transition-all" 
                      />
                    </div>
                    
                    <div className="pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                      <button 
                        type="button" 
                        onClick={handleTestTelegram}
                        disabled={isTesting}
                        className="w-full py-6 bg-slate-100 text-slate-600 rounded-[2rem] font-black uppercase tracking-widest text-sm shadow-md hover:bg-slate-200 transition-all flex items-center justify-center space-x-3 group disabled:opacity-50"
                      >
                        <Send className={`w-5 h-5 ${isTesting ? 'animate-pulse' : 'group-hover:translate-x-1 group-hover:-translate-y-1'} transition-transform`} />
                        <span>{isTesting ? 'GÖNDERİLİYOR...' : 'TEST MESAJI GÖNDER'}</span>
                      </button>
                      
                      <button 
                        type="submit" 
                        className="w-full py-6 bg-slate-950 text-white rounded-[2rem] font-black uppercase tracking-widest text-sm shadow-xl hover:bg-indigo-600 transition-all flex items-center justify-center space-x-3 group"
                      >
                        <Save className="w-5 h-5 group-hover:scale-110 transition-transform" />
                        <span>AYARLARI KAYDET</span>
                      </button>
                    </div>
                  </form>

                  <div className="mt-12 p-6 bg-amber-50 rounded-3xl border border-amber-100 flex items-start space-x-4">
                    <div className="p-2 bg-amber-100 text-amber-600 rounded-lg shrink-0"><Lock className="w-5 h-5" /></div>
                    <div className="text-[11px] font-medium text-amber-800 leading-relaxed">
                      <b>Nasıl Kullanılır?</b><br />
                      1. Telegram'da @BotFather'a giderek bir bot oluşturun ve <b>Token</b> kodunu alın.<br />
                      2. @userinfobot gibi bir bottan kendi <b>Chat ID</b> numaranızı öğrenin.<br />
                      3. Bilgileri yukarıdaki alanlara yapıştırıp önce "Test Mesajı Gönder" butonuyla doğrulayın, sonra kaydedin.
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        </main>
      </div>
    );
  }

  return <div className="h-screen bg-slate-950 flex items-center justify-center text-white font-black italic text-2xl uppercase tracking-widest animate-pulse">SİSTEM YÜKLENİYOR...</div>;
}

function SidebarItem({ icon, label, active, onClick }: any) {
  return (
    <button onClick={onClick} className={`w-full flex items-center space-x-4 p-4 rounded-2xl transition-all font-bold uppercase text-[10px] tracking-widest ${active ? 'bg-indigo-600 text-white shadow-xl shadow-indigo-500/20' : 'text-slate-400 hover:bg-slate-900 hover:text-white'}`}>
      {React.cloneElement(icon, { className: 'w-6 h-6' })}<span>{label}</span>
    </button>
  );
}
