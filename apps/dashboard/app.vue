<template>
  <main class="shell">
    <section>
      <p class="eyebrow">Phase 01</p>
      <h1>Payflow Engine Dashboard</h1>
      <p>Operator surfaces for merchants, reconciliation, and reporting will land in later phases.</p>
    </section>
  </main>
</template>

<style>
body {
  margin: 0;
  font-family: "IBM Plex Sans", sans-serif;
  background: linear-gradient(135deg, #f3efe4, #d8e6f0);
  color: #14213d;
}

.shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 2rem;
}

section {
  max-width: 44rem;
  background: rgba(255, 255, 255, 0.72);
  padding: 2rem;
  border: 1px solid rgba(20, 33, 61, 0.12);
  border-radius: 1.5rem;
  box-shadow: 0 20px 60px rgba(20, 33, 61, 0.12);
}

.eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.75rem;
}
</style>
