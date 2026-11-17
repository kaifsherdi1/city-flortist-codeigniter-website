// Flat line-art illustrations shown either side of the login card on wide screens.
// Realistic proportions, side profile, seated on office chairs; both people face the card.
// Left: a florist arranging a bouquet. Right: the shop owner checking orders on a laptop.

const INK = '#1f1d2b';
const SKIN = '#fbe3d3';
const HAIR = '#1f1d2b';
const LIME = '#b8e04a';
const LIME_DARK = '#9cc43a';
const NAVY = '#24304d';
const NAVY_DARK = '#18203a';
const SUIT = '#22433f';
const SUIT_DARK = '#17302d';
const PINK = '#e8619a';
const PURPLE = '#7353c9';
const LEAF = '#5fae6e';
const WOOD = '#c98b5a';
const CHAIR = '#8b9bb0';
const CHAIR_DARK = '#5f6b7a';

const ink = { stroke: INK, strokeWidth: 1.6, strokeLinejoin: 'round', strokeLinecap: 'round' };
const line = { fill: 'none', stroke: 'var(--art-line)', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' };
const soft = { fill: 'var(--art-soft)', stroke: 'var(--art-line)', strokeWidth: 2, strokeLinejoin: 'round' };

/** A five-petal flower head. */
function Bloom({ x, y, r = 8, color = PINK }) {
  return (
    <g>
      {[0, 72, 144, 216, 288].map((a) => (
        <ellipse key={a} cx={x} cy={y - r * 0.6} rx={r * 0.42} ry={r * 0.6} fill={color} stroke={INK} strokeWidth="1.2" transform={`rotate(${a} ${x} ${y})`} />
      ))}
      <circle cx={x} cy={y} r={r * 0.28} fill="#f7c948" stroke={INK} strokeWidth="1" />
    </g>
  );
}

function Leaf({ x, y, rot = 0, size = 1 }) {
  return <ellipse cx={x} cy={y} rx={4.5 * size} ry={10 * size} fill={LEAF} stroke={INK} strokeWidth="1.2" transform={`rotate(${rot} ${x} ${y})`} />;
}

/** Sleeve (outlined thick stroke) ending in a hand. */
function Arm({ d, color, hand, w = 12 }) {
  return (
    <g>
      <path d={d} fill="none" stroke={INK} strokeWidth={w + 3.2} strokeLinecap="round" strokeLinejoin="round" />
      <path d={d} fill="none" stroke={color} strokeWidth={w} strokeLinecap="round" strokeLinejoin="round" />
      <ellipse cx={hand[0]} cy={hand[1]} rx="6.5" ry="5" fill={SKIN} {...ink} />
    </g>
  );
}

/** Office chair seen from the side, person faces right (+x). */
function Chair() {
  return (
    <g>
      <rect x="74" y="210" width="22" height="96" rx="11" fill={CHAIR} {...ink} transform="rotate(-7 85 258)" />
      <path d="M92 300 L104 312" stroke={CHAIR_DARK} strokeWidth="5" strokeLinecap="round" />
      <rect x="113" y="318" width="8" height="52" fill={CHAIR_DARK} {...ink} />
      <path d="M117 370 L80 388 M117 370 L154 388 M117 370 L117 390" stroke={INK} strokeWidth="4.5" strokeLinecap="round" />
      <circle cx="78" cy="393" r="5.5" fill={INK} />
      <circle cx="156" cy="393" r="5.5" fill={INK} />
      <circle cx="117" cy="395" r="5.5" fill={INK} />
      <rect x="80" y="304" width="76" height="15" rx="7.5" fill={CHAIR} {...ink} />
    </g>
  );
}

/** Seated person facing right. `who` = 'florist' | 'owner'. */
function Person({ who }) {
  const florist = who === 'florist';
  const top = florist ? LIME : SUIT;
  const topDark = florist ? LIME_DARK : SUIT_DARK;
  const legs = florist ? NAVY : SUIT;
  const legsDark = florist ? NAVY_DARK : SUIT_DARK;

  return (
    <g>
      {/* far leg */}
      <path d="M100 294 L188 297 Q198 298 198 309 L196 319 L104 318 Z" fill={legsDark} {...ink} />
      <path d="M180 308 L180 388 L192 388 L196 306 Z" fill={legsDark} {...ink} />
      {/* near leg */}
      <path d="M98 292 Q95 316 112 320 L204 322 Q214 320 213 306 Q211 295 200 295 L120 289 Z" fill={legs} {...ink} />
      <path d="M197 312 L198 389 L210 389 L213 309 Z" fill={legs} {...ink} />

      {/* shoes */}
      {florist ? (
        <>
          <path d="M178 386 Q192 384 202 395 L190 395 L186 391 L182 391 L181 400 L178 400 Z" fill={INK} />
          <path d="M196 386 Q212 384 224 396 L210 396 L205 391 L200 391 L199 401 L196 401 Z" fill={INK} />
        </>
      ) : (
        <>
          <path d="M177 386 L192 386 Q204 389 206 399 L177 399 Z" fill={INK} />
          <path d="M195 386 L211 386 Q225 389 228 399 L195 399 Z" fill={INK} />
        </>
      )}

      {/* far arm: resting on the table */}
      <Arm d="M136 238 L152 274 L184 254" color={topDark} hand={[189, 252]} />

      {/* body */}
      <path
        d={florist ? 'M106 232 Q112 221 128 221 Q144 223 146 240 L142 268 Q140 290 134 301 L100 303 Q95 270 106 232 Z' : 'M102 232 Q108 219 128 219 Q148 221 150 240 L146 270 Q144 292 138 303 L98 305 Q92 270 102 232 Z'}
        fill={top}
        {...ink}
      />
      {florist ? (
        <path d="M110 246 Q118 262 116 290" fill="none" stroke={LIME_DARK} strokeWidth="1.6" strokeLinecap="round" />
      ) : (
        <>
          <path d="M133 221 L147 226 L140 244 Z" fill="#fff" {...ink} />
          <path d="M140 229 L144 231 L142 256 L138 252 Z" fill={INK} />
          <path d="M131 222 Q139 240 136 266" fill="none" {...ink} />
        </>
      )}

      {/* neck and head */}
      <path d="M124 208 L136 208 L135 223 L125 223 Z" fill={SKIN} {...ink} />
      <ellipse cx="132" cy="193" rx="14" ry="17" fill={SKIN} {...ink} />
      <path d="M145 188 L150 197 L145 199" fill={SKIN} {...ink} />
      {florist ? (
        <>
          <path d="M118 198 Q113 175 132 175 Q147 176 147 187 Q138 183 130 188 Q126 196 124 208 Q118 206 118 198 Z" fill={HAIR} />
          <circle cx="116" cy="180" r="7.5" fill={HAIR} />
        </>
      ) : (
        <>
          <path d="M118 192 Q116 172 134 172 Q148 173 148 184 Q140 180 133 182 L131 188 Q126 186 123 196 L120 200 Q117 197 118 192 Z" fill={HAIR} />
          <ellipse cx="125" cy="195" rx="3" ry="4.5" fill={SKIN} {...ink} />
        </>
      )}
      <path d="M138 186 L144 185.5" stroke={INK} strokeWidth="1.4" strokeLinecap="round" />
      <circle cx="141" cy="191" r="1.6" fill={INK} />
      <path d="M139 203 Q142.5 205.5 146 202.5" fill="none" stroke={INK} strokeWidth="1.4" strokeLinecap="round" />
      <circle cx="137" cy="199" r="2.6" fill={PINK} opacity=".3" />

      {/* near arm */}
      {florist ? (
        <>
          <path d="M173 236 Q177 220 184 206" fill="none" stroke={LEAF} strokeWidth="2.2" strokeLinecap="round" />
          <Leaf x={180} y={222} rot={35} size={0.6} />
          <Bloom x={185} y={202} r={9} color={PURPLE} />
          <Arm d="M122 238 L138 278 L168 242" color={top} hand={[171, 239]} w={13} />
        </>
      ) : (
        <Arm d="M120 238 L134 280 L176 257" color={top} hand={[181, 255]} w={13} />
      )}
    </g>
  );
}

function Table() {
  return (
    <g>
      <rect x="228" y="256" width="7" height="144" fill={WOOD} {...ink} />
      <rect x="342" y="256" width="7" height="144" fill={WOOD} {...ink} />
      <rect x="158" y="250" width="202" height="8" rx="2" fill={WOOD} {...ink} />
    </g>
  );
}

export function FloristArt(props) {
  return (
    <svg viewBox="0 0 400 440" role="img" aria-label="A florist arranging a bouquet at her work table" {...props}>
      {/* room */}
      <path d="M36 118h104" {...line} />
      <rect x="48" y="96" width="14" height="22" rx="2" {...soft} />
      <path d="M55 96 q-8 -10 -4 -18 M55 96 q6 -12 12 -14" {...line} />
      <rect x="96" y="88" width="8" height="30" {...soft} />
      <rect x="106" y="94" width="8" height="24" {...soft} />
      <rect x="116" y="104" width="18" height="14" {...soft} />
      <rect x="172" y="70" width="54" height="66" {...line} />
      <path d="M199 92 L213 106 L199 120 L185 106 Z" {...soft} />
      <rect x="262" y="56" width="98" height="120" {...line} />
      <path d="M262 82h98M286 56v26M310 56v26M334 56v26M262 82v94" {...line} />
      <path d="M30 156h22v-6h-22zM46 168h22v-6h-22z" {...line} />
      <path d="M48 403h320" {...line} />

      <Chair />
      <Person who="florist" />
      <Table />

      {/* vase with bouquet */}
      <path d="M262 250 Q258 220 246 196M262 250 Q262 214 264 186M262 250 Q272 222 288 200M262 250 Q256 230 250 214" fill="none" stroke={LEAF} strokeWidth="2.2" strokeLinecap="round" />
      <Leaf x={248} y={224} rot={-50} />
      <Leaf x={280} y={222} rot={45} />
      <Leaf x={270} y={204} rot={20} size={0.8} />
      <Bloom x={246} y={194} r={11} />
      <Bloom x={264} y={182} r={12} color="#f39bbf" />
      <Bloom x={289} y={198} r={11} color={PURPLE} />
      <Bloom x={252} y={213} r={8} color="#fbe3ec" />
      <path d="M250 251 L246 222 Q262 215 278 222 L274 251 Z" fill={PURPLE} {...ink} />
      <path d="M251 236 Q262 241 273 236" fill="none" stroke="#fff" strokeOpacity=".55" strokeWidth="2.5" strokeLinecap="round" />

      {/* wrapping paper, ribbon and scissors */}
      <path d="M298 249 L348 236 L342 250 Z" fill="#f6d3e1" {...ink} />
      <path d="M316 244 q6 -10 12 -2 q8 -8 12 3" fill="none" stroke={PINK} strokeWidth="2.4" strokeLinecap="round" />
      <circle cx="208" cy="245" r="3.6" fill="none" {...ink} />
      <circle cx="217" cy="246" r="3.6" fill="none" {...ink} />
      <path d="M211 243 L230 237M214 244 L232 241" {...ink} />

      {/* drifting petals */}
      <g className="art-float">
        <ellipse cx="226" cy="168" rx="3.5" ry="6" fill="#f39bbf" transform="rotate(30 226 168)" />
        <ellipse cx="312" cy="196" rx="3" ry="5.5" fill={PURPLE} opacity=".55" transform="rotate(-20 312 196)" />
      </g>
    </svg>
  );
}

export function OwnerArt(props) {
  return (
    <svg viewBox="0 0 400 440" role="img" aria-label="The shop owner checking new orders on a laptop" {...props}>
      {/* room (not mirrored) */}
      <path d="M262 118h104" {...line} />
      <rect x="270" y="88" width="8" height="30" {...soft} />
      <rect x="280" y="96" width="8" height="22" {...soft} />
      <rect x="292" y="104" width="22" height="14" {...soft} />
      <rect x="334" y="96" width="14" height="22" rx="2" {...soft} />
      <path d="M341 96 q-6 -12 -12 -14 M341 96 q8 -10 4 -18" {...line} />
      <rect x="176" y="64" width="54" height="66" {...line} />
      <path d="M203 112 q-12 -10 0 -26 q12 16 0 26 z" {...soft} />
      <path d="M348 156h22v-6h-22zM332 168h22v-6h-22z" {...line} />
      <path d="M32 403h320" {...line} />

      {/* the scene is drawn facing right, then mirrored so the owner faces the login card */}
      <g transform="translate(400 0) scale(-1 1)">
        <Chair />
        <Person who="owner" />
        <Table />
        {/* laptop: screen towards the owner */}
        <rect x="176" y="246" width="54" height="4" rx="1.5" fill="#cfd4dc" {...ink} />
        <path d="M224 249 L238 206 L243 207 L230 250 Z" fill="#9aa4b2" {...ink} />
        {/* mug */}
        <rect x="250" y="233" width="16" height="17" rx="2.5" fill="#fff" {...ink} />
        <path d="M266 237 q7 0 7 5 t-7 5" fill="none" {...ink} />
        <circle cx="258" cy="241.5" r="3" fill={PINK} />
        {/* plant */}
        <Leaf x={298} y={214} rot={-30} size={1.1} />
        <Leaf x={306} y={208} rot={0} size={1.2} />
        <Leaf x={315} y={214} rot={32} size={1.1} />
        <Bloom x={306} y={194} r={8} />
        <path d="M292 250 L295 228 L317 228 L320 250 Z" fill={LIME} {...ink} />
        {/* papers */}
        <path d="M330 248 L356 244 L358 249 L332 251 Z" fill="#fff" {...ink} />
      </g>

      {/* new-order notification */}
      <g className="art-float">
        <rect x="40" y="96" width="122" height="50" rx="14" fill="#fff" {...ink} />
        <path d="M128 145 L140 160 L142 145" fill="#fff" {...ink} />
        <path d="M129 144.5 L141.5 144.5" stroke="#fff" strokeWidth="3" />
        <circle cx="64" cy="121" r="11" fill="#25d366" stroke={INK} strokeWidth="1.4" />
        <path d="M59 121 l4 4 l7 -8" fill="none" stroke="#fff" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
        <rect x="82" y="111" width="64" height="7" rx="3.5" fill={PURPLE} opacity=".85" />
        <rect x="82" y="124" width="42" height="7" rx="3.5" fill="#d9d4e6" />
      </g>
      <g className="art-float slow">
        <path d="M232 176 c0 -6 8 -8 10 -2 c2 -6 10 -4 10 2 c0 7 -10 12 -10 12 s-10 -5 -10 -12z" fill={PINK} stroke={INK} strokeWidth="1.4" />
      </g>
    </svg>
  );
}
