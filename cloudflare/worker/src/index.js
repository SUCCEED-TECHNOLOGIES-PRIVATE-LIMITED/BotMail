import PostalMime from 'postal-mime';

export default {
  async email(message, env, ctx) {
    const raw = await new Response(message.raw).arrayBuffer();
    const email = await new PostalMime().parse(raw);
    const attachments = [];

    for (const attachment of email.attachments || []) {
      const bytes = attachment.content instanceof Uint8Array
        ? attachment.content
        : new Uint8Array(attachment.content || []);

      attachments.push({
        filename: attachment.filename || 'attachment',
        mime: attachment.mimeType || 'application/octet-stream',
        size: bytes.byteLength,
        data: bufferToBase64(bytes),
      });
    }

    const payload = {
      from: email.from?.address || message.from,
      to: message.to,
      subject: email.subject || '',
      text: email.text || '',
      html: email.html || '',
      headers: (email.headers || []).map((header) => ({
        key: header.key,
        value: header.value,
      })),
      attachments,
      message_id: email.messageId || '',
    };

    const response = await fetch(env.WEBHOOK_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Secret': env.WEBHOOK_SECRET,
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      message.setReject(`BotMail webhook failed (${response.status})`);
    }
  },
};

function bufferToBase64(bytes) {
  const chunkSize = 0x8000;
  let binary = '';

  for (let index = 0; index < bytes.length; index += chunkSize) {
    const chunk = bytes.subarray(index, index + chunkSize);
    binary += String.fromCharCode.apply(null, chunk);
  }

  return btoa(binary);
}
