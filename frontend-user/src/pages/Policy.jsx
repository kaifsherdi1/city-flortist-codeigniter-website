import { Link } from 'react-router-dom';
import { useSite } from '../lib/site.jsx';
import { usePageMeta } from '../lib/seo.js';

const UPDATED = '15 November 2026';

/** Contact block shared by both pages: always the details saved in Admin → Settings. */
function Contact({ settings }) {
  const phone = settings.shop_phone || (settings.whatsapp_number ? '+' + settings.whatsapp_number : '');
  return (
    <ul className="policy-contact">
      {phone && <li>Phone / WhatsApp: <a href={`tel:${phone.replace(/\s/g, '')}`}>{phone}</a></li>}
      {settings.email && <li>Email: <a href={`mailto:${settings.email}`}>{settings.email}</a></li>}
      {settings.address && <li>Shop: City Florist, {settings.address}</li>}
    </ul>
  );
}

export function Privacy() {
  const { settings } = useSite();
  usePageMeta({ title: 'Privacy Policy', description: 'How City Florist, Hubballi uses the name and phone number you share when you order or write a review.' });

  return (
    <article className="policy container">
      <span className="eyebrow">Last updated {UPDATED}</span>
      <h1 className="display">Privacy Policy</h1>
      <p className="policy-lead">
        This page explains what City Florist, Hubballi (“we”) collects through this website, why, and what you can ask us to do with it.
        It is written to follow India’s Digital Personal Data Protection Act, 2023.
      </p>

      <h2>What we collect</h2>
      <ul>
        <li><b>When you tap Buy:</b> your name, mobile number, the product, and the delivery date and message if you add them.</li>
        <li><b>When you write a review:</b> your name, rating and review, and your mobile number if you choose to give it.</li>
        <li><b>Basic technical data:</b> your device’s IP address, used only to stop spam and repeated misuse (for example, too many orders from one device).</li>
      </ul>
      <p>We do not ask for payment details, ID documents or passwords on this website.</p>

      <h2>Why we use it</h2>
      <ul>
        <li>To prepare your order, contact you on WhatsApp or by phone to confirm design, price, delivery time and payment, and deliver it.</li>
        <li>To show your review on the website once we have read it, with only your first name and the first letter of your surname. Your phone number is never shown; it is only used to mark the review “Verified order” if it matches an order.</li>
        <li>To keep basic records of orders for our accounts.</li>
      </ul>
      <p>We do not sell your details, use them for advertising, or send marketing messages unless you ask us to.</p>

      <h2>Who else handles it</h2>
      <ul>
        <li><b>WhatsApp (Meta):</b> when you press Send, your order message goes through WhatsApp, under WhatsApp’s own privacy policy.</li>
        <li><b>Our web hosting provider</b> stores the website and its database for us.</li>
        <li><b>Google:</b> the map and the fonts on this site load from Google, which may receive your IP address. Google’s privacy policy applies to those.</li>
      </ul>

      <h2>Cookies</h2>
      <p>
        The shop pages use no tracking or advertising cookies. A login cookie is used only on the shop owner’s dashboard. The embedded
        Google map may set its own cookies.
      </p>

      <h2>How long we keep it</h2>
      <p>
        Orders are kept as long as we need them to serve you and for our accounts, and then deleted. Reviews stay on the website until you
        ask us to remove them or we take them down.
      </p>

      <h2>Your rights</h2>
      <p>You can ask us to:</p>
      <ul>
        <li>tell you what details of yours we hold,</li>
        <li>correct or update them,</li>
        <li>delete them (for example, an old order or your review), and</li>
        <li>stop using them, by withdrawing your consent.</li>
      </ul>
      <p>
        Contact us using the details below. We reply within 7 days. If you are not satisfied with our answer, you may complain to the Data
        Protection Board of India.
      </p>

      <h2>Children</h2>
      <p>This website is meant for adults placing orders. If you are under 18, please ask a parent or guardian to order for you.</p>

      <h2>Contact for privacy questions and complaints</h2>
      <Contact settings={settings} />

      <p className="policy-foot">If we change this policy, the new version will appear on this page with a new date. See also our <Link to="/terms">Delivery, Cancellation & Refund Terms</Link>.</p>
    </article>
  );
}

export function Terms() {
  const { settings } = useSite();
  usePageMeta({ title: 'Delivery, Cancellation & Refund Terms', description: 'How ordering, delivery, cancellations and refunds work at City Florist, Hubballi.' });

  return (
    <article className="policy container">
      <span className="eyebrow">Last updated {UPDATED}</span>
      <h1 className="display">Delivery, Cancellation & Refund Terms</h1>
      <p className="policy-lead">These terms apply to orders placed through this website or on WhatsApp with City Florist, Hubballi.</p>

      <h2>Orders</h2>
      <ul>
        <li>Tapping <b>Buy</b> sends us your request. Your order is confirmed only when we agree the design, price, delivery time and payment with you on WhatsApp or by phone.</li>
        <li>Flowers are fresh and natural, so shades and sizes can differ a little from the photos. If a flower is not available that day, we will suggest a similar one of equal value and use it only with your agreement.</li>
        <li>Some photos, such as room decoration, show the style of our work. Every setup is designed for your room, theme and budget.</li>
      </ul>

      <h2>Prices and payment</h2>
      <ul>
        <li>Prices shown on the website include the item. Delivery charges, if any, are told to you before you confirm.</li>
        <li>Wholesale flower prices change daily with the market and are given on WhatsApp.</li>
        <li>There is no online payment on this website. Payment (UPI, cash or as agreed) is arranged with you on WhatsApp. For large orders, wedding decoration and custom work we may ask for an advance.</li>
      </ul>

      <h2>Delivery</h2>
      <ul>
        <li>We deliver in Hubballi and nearby areas. You can also collect your order from our shop.</li>
        <li>Same-day delivery is possible for many items when ordered early in the day. Delivery times are agreed with you and are approximate, as traffic and weather can cause delays.</li>
        <li>Please give a correct address and a phone number that will be answered. If no one is available to receive the order, we will call you to arrange it; a second delivery may be charged.</li>
        <li>Cakes, wedding decoration and large orders need some notice. Please order these in advance.</li>
      </ul>

      <h2>Cancellations</h2>
      <ul>
        <li>You can cancel free of charge before we start preparing your order.</li>
        <li>Once flowers have been bought or arranged for your order, or a decoration team has been booked for your date, the advance may not be refundable, because fresh flowers cannot be reused.</li>
        <li>To cancel or change an order, message us on WhatsApp as early as possible.</li>
      </ul>

      <h2>Problems and refunds</h2>
      <ul>
        <li>If something is wrong with your order (damaged, wrong item or not as agreed), tell us on the same day with a photo.</li>
        <li>We will replace the item or refund the amount for the affected part, whichever is fair for the problem.</li>
        <li>Refunds are paid back to the original payment method (or by UPI for cash payments) within 7 working days of approval.</li>
        <li>Natural differences in flower colour, size or opening, or delays caused by a wrong address or an unanswered phone, are not grounds for a refund.</li>
      </ul>

      <h2>Contact</h2>
      <Contact settings={settings} />

      <p className="policy-foot">See also our <Link to="/privacy">Privacy Policy</Link>.</p>
    </article>
  );
}
