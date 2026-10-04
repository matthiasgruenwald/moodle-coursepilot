---
name: drawing-canvas
description: Read this when learners must draw circuits, UML, flowcharts, wireframes, network topologies or sketches.
---

# Reference: drawing canvas

For drawing tasks, include this canvas rather than an empty box or
placeholder div. Translate visible controls/instructions into the course language.

Adapt:

- CANVAS_ID: unique ID, e.g. circuit, uml or wireframe.
- CANVAS_HEIGHT: 200/300/400/500 as needed.
- PHASE_COLOR: current phase border color.
- FILENAME: e.g. circuit.png or uml-diagram.png.

```html
<div id="toolbar_[CANVAS_ID]" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
  <button onclick="setTool_[CANVAS_ID]('pen')" id="btn_pen_[CANVAS_ID]"
    style="padding:6px 12px;border:2px solid [PHASE_COLOR];border-radius:6px;background:[PHASE_COLOR];color:#fff;cursor:pointer;font-size:0.85em;">&#9998; Pen</button>
  <button onclick="setTool_[CANVAS_ID]('line')" id="btn_line_[CANVAS_ID]"
    style="padding:6px 12px;border:2px solid #555;border-radius:6px;background:#fff;color:#333;cursor:pointer;font-size:0.85em;">&#9135; Line</button>
  <button onclick="setTool_[CANVAS_ID]('rect')" id="btn_rect_[CANVAS_ID]"
    style="padding:6px 12px;border:2px solid #555;border-radius:6px;background:#fff;color:#333;cursor:pointer;font-size:0.85em;">&#9645; Rectangle</button>
  <button onclick="setTool_[CANVAS_ID]('eraser')" id="btn_eraser_[CANVAS_ID]"
    style="padding:6px 12px;border:2px solid #555;border-radius:6px;background:#fff;color:#333;cursor:pointer;font-size:0.85em;">&#9746; Eraser</button>
  <span style="color:#ccc;">|</span>
  <label style="font-size:0.85em;color:#555;">Color:</label>
  <input type="color" id="color_[CANVAS_ID]" value="#000000" style="width:32px;height:28px;border:1px solid #ccc;border-radius:4px;cursor:pointer;padding:2px;">
  <label style="font-size:0.85em;color:#555;">Size:</label>
  <input type="range" id="size_[CANVAS_ID]" min="1" max="20" value="2" style="width:70px;">
  <span id="sizelabel_[CANVAS_ID]" style="font-size:0.85em;color:#555;">2px</span>
  <span style="color:#ccc;">|</span>
  <button onclick="undoCanvas_[CANVAS_ID]()"
    style="padding:6px 12px;border:2px solid #1565C0;border-radius:6px;background:#fff;color:#1565C0;cursor:pointer;font-size:0.85em;">&#8617; Undo</button>
  <button onclick="clearCanvas_[CANVAS_ID]()"
    style="padding:6px 12px;border:2px solid #e53935;border-radius:6px;background:#fff;color:#e53935;cursor:pointer;font-size:0.85em;">&#128465; Clear</button>
  <button onclick="downloadCanvas_[CANVAS_ID]()"
    style="padding:6px 12px;border:2px solid #2E7D32;border-radius:6px;background:#2E7D32;color:#fff;cursor:pointer;font-size:0.85em;">&#128229; Save as PNG</button>
</div>
<style>@media print { #toolbar_[CANVAS_ID] { display:none !important; } }</style>

<canvas id="canvas_[CANVAS_ID]" width="900" height="[CANVAS_HEIGHT]"
  style="border:2px solid [PHASE_COLOR];border-radius:8px;cursor:crosshair;background:#fff;width:100%;touch-action:none;display:block;"></canvas>

<script>
(function() {
  const canvas = document.getElementById('canvas_[CANVAS_ID]');
  const ctx = canvas.getContext('2d');
  const PHASE_COLOR = '[PHASE_COLOR]';
  let tool = 'pen', drawing = false, startX = 0, startY = 0, lastX = 0, lastY = 0;
  let history = [], snapshot = null;

  function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / rect.width, sy = canvas.height / rect.height;
    const src = e.touches ? e.touches[0] : e;
    return { x: (src.clientX - rect.left) * sx, y: (src.clientY - rect.top) * sy };
  }
  function saveHistory() {
    history.push(ctx.getImageData(0, 0, canvas.width, canvas.height));
    if (history.length > 30) history.shift();
  }
  function startDraw(e) {
    e.preventDefault(); drawing = true;
    const p = getPos(e); startX = lastX = p.x; startY = lastY = p.y;
    saveHistory();
    if (tool === 'line' || tool === 'rect') snapshot = ctx.getImageData(0, 0, canvas.width, canvas.height);
  }
  function draw(e) {
    e.preventDefault(); if (!drawing) return;
    const p = getPos(e);
    const color = document.getElementById('color_[CANVAS_ID]').value;
    const size = parseInt(document.getElementById('size_[CANVAS_ID]').value);
    if (tool === 'pen') {
      ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y);
      ctx.strokeStyle = color; ctx.lineWidth = size; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.stroke();
      lastX = p.x; lastY = p.y;
    } else if (tool === 'eraser') {
      ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y);
      ctx.strokeStyle = '#ffffff'; ctx.lineWidth = size * 4; ctx.lineCap = 'round'; ctx.stroke();
      lastX = p.x; lastY = p.y;
    } else if (tool === 'line') {
      ctx.putImageData(snapshot, 0, 0);
      ctx.beginPath(); ctx.moveTo(startX, startY); ctx.lineTo(p.x, p.y);
      ctx.strokeStyle = color; ctx.lineWidth = size; ctx.lineCap = 'round'; ctx.stroke();
    } else if (tool === 'rect') {
      ctx.putImageData(snapshot, 0, 0);
      ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = size;
      ctx.strokeRect(startX, startY, p.x - startX, p.y - startY);
    }
  }
  function stopDraw(e) { e.preventDefault(); drawing = false; snapshot = null; }

  canvas.addEventListener('mousedown', startDraw); canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDraw); canvas.addEventListener('mouseleave', stopDraw);
  canvas.addEventListener('touchstart', startDraw, {passive:false});
  canvas.addEventListener('touchmove', draw, {passive:false});
  canvas.addEventListener('touchend', stopDraw, {passive:false});

  document.getElementById('size_[CANVAS_ID]').addEventListener('input', function() {
    document.getElementById('sizelabel_[CANVAS_ID]').textContent = this.value + 'px';
  });
  function updateButtons(active) {
    ['pen','line','rect','eraser'].forEach(function(t) {
      const btn = document.getElementById('btn_' + t + '_[CANVAS_ID]');
      if (!btn) return;
      btn.style.background = (t === active) ? PHASE_COLOR : '#fff';
      btn.style.color = (t === active) ? '#fff' : '#333';
      btn.style.borderColor = (t === active) ? PHASE_COLOR : '#555';
    });
    canvas.style.cursor = (active === 'eraser') ? 'cell' : 'crosshair';
  }
  window['setTool_[CANVAS_ID]'] = function(t) { tool = t; updateButtons(t); };
  window['undoCanvas_[CANVAS_ID]'] = function() { if (history.length > 0) ctx.putImageData(history.pop(), 0, 0); };
  window['clearCanvas_[CANVAS_ID]'] = function() {
    if (confirm('Clear the drawing?')) { saveHistory(); ctx.clearRect(0, 0, canvas.width, canvas.height); }
  };
  window['downloadCanvas_[CANVAS_ID]'] = function() {
    const l = document.createElement('a'); l.download = '[FILENAME]';
    l.href = canvas.toDataURL('image/png'); l.click();
  };
  updateButtons('pen');
})();
</script>
<p style="font-size:0.85em;color:#666;margin-top:8px;">
  &#128161; Tip: pen for freehand, line for straight connections, rectangle for nodes/blocks.
  Undo reverses up to 30 steps. Save the PNG and upload it to Moodle.
  Alternatively, photograph a paper sketch and submit the image.
</p>
```

Each canvas on a page needs its own CANVAS_ID.
