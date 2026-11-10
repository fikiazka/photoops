(function () {
  var body = document.body;
  var pemicuTerakhir = null;

  function nilaiBidang(form, nama) {
    var pilihan = form.querySelectorAll('[name="' + nama + '"]');
    for (var i = 0; i < pilihan.length; i++) {
      var el = pilihan[i];
      if (el.type === 'radio' || el.type === 'checkbox') {
        if (el.checked) {
          return el.value;
        }
      } else {
        return el.value;
      }
    }
    return '';
  }

  function perbaruiSaat(cakupan) {
    cakupan.querySelectorAll('[data-saat]').forEach(function (el) {
      var form = el.closest('form');
      if (!form) {
        return;
      }
      var aturan = el.getAttribute('data-saat').split('=');
      var cocok = aturan[1].split('|').indexOf(nilaiBidang(form, aturan[0])) !== -1;
      el.hidden = !cocok;
    });
  }

  function isiForm(dialog, data) {
    var form = dialog.querySelector('form');
    if (!form) {
      return;
    }
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.name === 'csrf') {
        return;
      }
      var nama = el.name.replace(/\[\]$/, '');
      var ada = Object.prototype.hasOwnProperty.call(data, nama);
      if (el.type === 'hidden' && !ada && nama !== 'id') {
        return;
      }
      var nilai = ada ? data[nama] : (el.getAttribute('data-bawaan') || '');
      if (el.type === 'checkbox' || el.type === 'radio') {
        var daftar = Array.isArray(nilai) ? nilai.map(String) : [String(nilai)];
        el.checked = daftar.indexOf(el.value) !== -1;
      } else {
        el.value = nilai === null ? '' : String(nilai);
      }
    });
    dialog.querySelectorAll('.galat').forEach(function (el) {
      el.remove();
    });
    dialog.querySelectorAll('[aria-invalid]').forEach(function (el) {
      el.removeAttribute('aria-invalid');
      el.removeAttribute('aria-describedby');
    });
    dialog.querySelectorAll('[data-isi-teks]').forEach(function (el) {
      var kunci = el.getAttribute('data-isi-teks');
      if (!el.hasAttribute('data-teks-asli')) {
        el.setAttribute('data-teks-asli', el.textContent);
      }
      el.textContent = Object.prototype.hasOwnProperty.call(data, kunci) ? data[kunci] : el.getAttribute('data-teks-asli');
    });
    var kirim = form.querySelector('[type="submit"][data-isi-teks="_tombol"]');
    if (kirim && data._kelas) {
      kirim.className = data._kelas;
    }
    perbaruiSaat(dialog);
  }

  function bukaDialog(id, pemicu) {
    var dialog = document.getElementById(id);
    if (!dialog || typeof dialog.showModal !== 'function' || dialog.open) {
      return;
    }
    if (pemicu && pemicu.hasAttribute('data-isi')) {
      try {
        isiForm(dialog, JSON.parse(pemicu.getAttribute('data-isi')) || {});
      } catch (galat) {
        isiForm(dialog, {});
      }
    } else {
      perbaruiSaat(dialog);
    }
    pemicuTerakhir = pemicu || document.activeElement;
    dialog.showModal();
    var fokus = dialog.querySelector('[aria-invalid="true"], [autofocus], .dialog-badan input:not([type="hidden"]):not([disabled]), .dialog-badan select, .dialog-badan textarea');
    if (fokus) {
      fokus.focus();
    }
  }

  function aturSidebar(terbuka) {
    var tombol = document.querySelector('[data-sidebar-toggle]');
    var latar = document.querySelector('[data-sidebar-tutup]');
    body.classList.toggle('menu-terbuka', terbuka);
    if (tombol) {
      tombol.setAttribute('aria-expanded', terbuka ? 'true' : 'false');
      tombol.setAttribute('aria-label', terbuka ? 'Tutup menu' : 'Buka menu');
    }
    if (latar) {
      latar.hidden = !terbuka;
    }
    if (terbuka) {
      var pertama = document.querySelector('#sidebar a, #sidebar button');
      if (pertama) {
        pertama.focus();
      }
    } else if (tombol) {
      tombol.focus();
    }
  }

  function hitungPersiapan(form) {
    var kotak = form.querySelectorAll('input[name="diperiksa[]"]');
    var centang = form.querySelectorAll('input[name="diperiksa[]"]:checked').length;
    var angka = form.querySelector('[data-progres-angka]');
    var isi = form.querySelector('.bilah-isi');
    if (angka) {
      angka.textContent = centang;
    }
    if (isi && kotak.length) {
      isi.style.width = Math.round(centang / kotak.length * 100) + '%';
    }
  }

  document.addEventListener('click', function (ev) {
    var buka = ev.target.closest('[data-buka]');
    if (buka) {
      ev.preventDefault();
      bukaDialog(buka.getAttribute('data-buka'), buka);
      return;
    }

    var tutup = ev.target.closest('[data-tutup]');
    if (tutup) {
      var induk = tutup.closest('dialog');
      if (induk) {
        induk.close();
      }
      return;
    }

    if (ev.target.tagName === 'DIALOG') {
      ev.target.close();
      return;
    }

    if (ev.target.closest('[data-sidebar-toggle]')) {
      aturSidebar(!body.classList.contains('menu-terbuka'));
      return;
    }

    if (ev.target.closest('[data-sidebar-tutup]')) {
      aturSidebar(false);
      return;
    }

    var lihat = ev.target.closest('[data-lihat-sandi]');
    if (lihat) {
      var sandi = document.getElementById(lihat.getAttribute('data-lihat-sandi'));
      if (sandi) {
        var tampil = sandi.type === 'password';
        sandi.type = tampil ? 'text' : 'password';
        lihat.setAttribute('aria-pressed', tampil ? 'true' : 'false');
        lihat.setAttribute('aria-label', tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
        lihat.querySelector('[data-ikon-tampil]').hidden = tampil;
        lihat.querySelector('[data-ikon-sembunyi]').hidden = !tampil;
      }
      return;
    }

    var semua = ev.target.closest('[data-centang-semua]');
    if (semua) {
      var formP = semua.closest('form');
      var kotakP = formP.querySelectorAll('input[name="diperiksa[]"]:not(:disabled)');
      var semuaTercentang = Array.prototype.every.call(kotakP, function (k) { return k.checked; });
      kotakP.forEach(function (k) {
        k.checked = !semuaTercentang;
      });
      semua.textContent = semuaTercentang ? 'Centang semua' : 'Kosongkan semua';
      hitungPersiapan(formP);
    }
  });

  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.form && el.name) {
      perbaruiSaat(el.form);
    }
    if (el.hasAttribute('data-kirim-otomatis') && el.form) {
      el.form.submit();
    }
    if (el.hasAttribute('data-pilih-semua')) {
      el.form.querySelectorAll('input[name="' + el.getAttribute('data-pilih-semua') + '"]:not(:disabled)').forEach(function (k) {
        k.checked = el.checked;
      });
    }
    var persiapan = el.closest('[data-persiapan]');
    if (persiapan) {
      hitungPersiapan(persiapan);
    }
  });

  document.addEventListener('submit', function (ev) {
    var tombol = ev.target.querySelector('[type="submit"]');
    if (tombol) {
      setTimeout(function () {
        tombol.disabled = true;
      }, 0);
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && body.classList.contains('menu-terbuka')) {
      aturSidebar(false);
    }
  });

  document.querySelectorAll('dialog').forEach(function (dialog) {
    dialog.addEventListener('close', function () {
      if (pemicuTerakhir && typeof pemicuTerakhir.focus === 'function') {
        pemicuTerakhir.focus();
      }
      pemicuTerakhir = null;
    });
  });

  perbaruiSaat(document);

  var modalAwal = body.getAttribute('data-modal-awal');
  if (modalAwal) {
    bukaDialog(modalAwal, null);
  }
})();
