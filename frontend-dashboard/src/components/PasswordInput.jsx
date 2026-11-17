import { useState } from 'react';
import Icon from './Icon.jsx';

/** Password box with an eye button to show or hide what was typed. */
export default function PasswordInput({ label = 'Password', ...input }) {
  const [shown, setShown] = useState(false);
  return (
    <label>
      {label}
      <span className="input-wrap">
        <input type={shown ? 'text' : 'password'} required {...input} />
        <button
          type="button"
          className="input-eye"
          onClick={() => setShown((s) => !s)}
          aria-label={shown ? 'Hide password' : 'Show password'}
          aria-pressed={shown}
          title={shown ? 'Hide password' : 'Show password'}
        >
          <Icon name={shown ? 'eyeOff' : 'eye'} size={19} />
        </button>
      </span>
    </label>
  );
}
