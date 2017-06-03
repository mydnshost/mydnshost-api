Hi {{ user.getRealName() }},

A new 2FA Key has been added to your account on {{ sitename }}.

    Description: {{ twofactorkey.getDescription() }}

{% include 'footer.tpl' %}
