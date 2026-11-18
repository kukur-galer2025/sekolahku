<nav class="ltabs" aria-label="Menu penggajian">
  <a href="{{ route('panel.payroll') }}" class="{{ request()->routeIs('panel.payroll', 'panel.payroll.show') ? 'on' : '' }}"><x-ti n="file-spreadsheet"/> Periode gaji</a>
  <a href="{{ route('panel.payroll.employees') }}" class="{{ request()->routeIs('panel.payroll.employees*') ? 'on' : '' }}"><x-ti n="users"/> Data karyawan</a>
</nav>
